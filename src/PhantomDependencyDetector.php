<?php

declare(strict_types=1);

namespace AlexKassel\DevKit;

use JsonException;
use SplFileInfo;

class PhantomDependencyDetector
{
    /**
     * @return array{
     *     status: string,
     *     undeclared_packages: list<string>,
     *     violations: list<array{class: string, file: string, package: string}>,
     *     message: string
     * }
     */
    public function detect(string $root, string $packagePath): array
    {
        $root = realpath($root) ?: $root;
        $packagePath = realpath($packagePath) ?: $packagePath;

        $packageComposerPath = $packagePath.DIRECTORY_SEPARATOR.'composer.json';
        if (! file_exists($packageComposerPath)) {
            return [
                'status' => 'not_configured',
                'undeclared_packages' => [],
                'violations' => [],
                'message' => 'No composer.json found in package directory.',
            ];
        }

        $packageManifest = $this->readJson($packageComposerPath);
        if ($packageManifest === null) {
            return [
                'status' => 'failed',
                'undeclared_packages' => [],
                'violations' => [],
                'message' => 'Invalid composer.json in package directory.',
            ];
        }

        $installedJsonPath = $root.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'composer'.DIRECTORY_SEPARATOR.'installed.json';
        if (! file_exists($installedJsonPath)) {
            return [
                'status' => 'skipped',
                'undeclared_packages' => [],
                'violations' => [],
                'message' => 'Root vendor/composer/installed.json not found.',
            ];
        }

        $installedData = $this->readJson($installedJsonPath);
        if ($installedData === null) {
            return [
                'status' => 'skipped',
                'undeclared_packages' => [],
                'violations' => [],
                'message' => 'Cannot parse root installed.json.',
            ];
        }

        /** @var list<array<string, mixed>> $installedPackages */
        $installedPackages = $installedData['packages'] ?? (array_is_list($installedData) ? $installedData : []);

        // 1. Build namespace map and dependency map from installed.json
        $namespaceMap = []; // prefix => package_name
        $dependencyTree = []; // package_name => list<package_name>

        foreach ($installedPackages as $pkg) {
            $pkgName = (string) ($pkg['name'] ?? '');
            if ($pkgName === '') {
                continue;
            }

            $requires = isset($pkg['require']) && is_array($pkg['require']) ? array_keys($pkg['require']) : [];
            $dependencyTree[$pkgName] = $requires;

            // PSR-4 prefixes
            $psr4 = $pkg['autoload']['psr-4'] ?? [];
            if (is_array($psr4)) {
                foreach (array_keys($psr4) as $prefix) {
                    $normPrefix = trim((string) $prefix, '\\').'\\';
                    $namespaceMap[$normPrefix] = $pkgName;
                }
            }

            // PSR-0 prefixes
            $psr0 = $pkg['autoload']['psr-0'] ?? [];
            if (is_array($psr0)) {
                foreach (array_keys($psr0) as $prefix) {
                    $normPrefix = trim((string) $prefix, '\\').'\\';
                    $namespaceMap[$normPrefix] = $pkgName;
                }
            }
        }

        // Sort namespace map by longest prefix first for greedy matching
        uksort($namespaceMap, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        // 2. Resolve all allowed packages (declared require + recursive dependencies)
        $declaredRequire = isset($packageManifest['require']) && is_array($packageManifest['require'])
            ? array_keys($packageManifest['require'])
            : [];

        $packageName = (string) ($packageManifest['name'] ?? '');

        $allowedPackages = [];
        $allowedPackages[$packageName] = true;

        $collectDependencies = function (string $pkg) use (&$collectDependencies, &$allowedPackages, $dependencyTree): void {
            if (isset($allowedPackages[$pkg])) {
                return;
            }
            $allowedPackages[$pkg] = true;

            $deps = $dependencyTree[$pkg] ?? [];
            foreach ($deps as $dep) {
                if (is_string($dep) && ! str_starts_with($dep, 'php') && ! str_starts_with($dep, 'ext-')) {
                    $collectDependencies($dep);
                }
            }
        };

        foreach ($declaredRequire as $dep) {
            if (is_string($dep) && ! str_starts_with($dep, 'php') && ! str_starts_with($dep, 'ext-')) {
                $collectDependencies($dep);
            }
        }

        // 3. Collect package's own namespaces
        $ownPrefixes = [];
        $ownAutoload = array_merge(
            (array) ($packageManifest['autoload']['psr-4'] ?? []),
            (array) ($packageManifest['autoload-dev']['psr-4'] ?? [])
        );
        foreach (array_keys($ownAutoload) as $prefix) {
            $ownPrefixes[] = trim((string) $prefix, '\\').'\\';
        }

        // 4. Scan all .php files in src/
        $srcDir = $packagePath.DIRECTORY_SEPARATOR.'src';
        if (! is_dir($srcDir)) {
            return [
                'status' => 'passed',
                'undeclared_packages' => [],
                'violations' => [],
                'message' => 'No src/ directory to scan.',
            ];
        }

        $violations = [];
        $undeclaredPackages = [];

        $files = $this->scanPhpFiles($srcDir);
        foreach ($files as $file) {
            $content = (string) file_get_contents($file->getPathname());
            $relFile = str_replace([$packagePath.DIRECTORY_SEPARATOR, $packagePath.'/'], '', $file->getPathname());
            $relFile = str_replace('\\', '/', $relFile);

            $classes = $this->extractReferencedClasses($content);

            foreach ($classes as $class) {
                $normClass = ltrim($class, '\\');

                // Check if class belongs to own package
                $isOwn = false;
                foreach ($ownPrefixes as $ownPrefix) {
                    if (str_starts_with($normClass, $ownPrefix)) {
                        $isOwn = true;
                        break;
                    }
                }
                if ($isOwn) {
                    continue;
                }

                // Check if class is built-in PHP
                if ($this->isBuiltIn($normClass)) {
                    continue;
                }

                // Find providing package
                $providingPackage = null;
                foreach ($namespaceMap as $prefix => $pkg) {
                    if (str_starts_with($normClass, $prefix)) {
                        $providingPackage = $pkg;
                        break;
                    }
                }

                if ($providingPackage !== null && ! isset($allowedPackages[$providingPackage])) {
                    $violations[] = [
                        'class' => $normClass,
                        'file' => $relFile,
                        'package' => $providingPackage,
                    ];
                    $undeclaredPackages[$providingPackage] = true;
                }
            }
        }

        $undeclaredList = array_keys($undeclaredPackages);
        sort($undeclaredList);

        if (empty($violations)) {
            return [
                'status' => 'passed',
                'undeclared_packages' => [],
                'violations' => [],
                'message' => 'No phantom dependencies detected. All referenced namespaces are declared in composer.json require.',
            ];
        }

        return [
            'status' => 'failed',
            'undeclared_packages' => $undeclaredList,
            'violations' => $violations,
            'message' => sprintf(
                'Phantom dependencies detected: %d class reference(s) belong to undeclared package(s): %s',
                count($violations),
                implode(', ', $undeclaredList)
            ),
        ];
    }

    /**
     * @return list<SplFileInfo>
     */
    private function scanPhpFiles(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * @return list<string>
     */
    private function extractReferencedClasses(string $phpCode): array
    {
        $classes = [];

        // Match use statements: use Foo\Bar\Baz; use Foo\Bar\Baz as Alias;
        if (preg_match_all('/^\s*use\s+(?:function\s+|const\s+)?([\\\\a-zA-Z0-9_]+)(?:\s+as\s+[a-zA-Z0-9_]+)?\s*;/m', $phpCode, $matches)) {
            foreach ($matches[1] as $imported) {
                if (str_contains($imported, '\\')) {
                    $classes[] = $imported;
                }
            }
        }

        // Match inline fully-qualified class names: \Foo\Bar\Baz
        if (preg_match_all('/(?<=\s|\(|\[|,|\||&|<)\\\\([a-zA-Z0-9_]+(?:\\\\[a-zA-Z0-9_]+)+)/', $phpCode, $matches)) {
            foreach ($matches[1] as $fqcn) {
                $classes[] = $fqcn;
            }
        }

        return array_values(array_unique($classes));
    }

    private function isBuiltIn(string $class): bool
    {
        if (! str_contains($class, '\\')) {
            return true;
        }

        // Standard PHP namespaces/extensions
        $coreNamespaces = [
            'Composer\\',
            'SessionHandler',
            'UnitEnum',
            'BackedEnum',
        ];

        foreach ($coreNamespaces as $ns) {
            if (str_starts_with($class, $ns)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJson(string $path): ?array
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        try {
            /** @var array<string, mixed>|null $data */
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

            return is_array($data) ? $data : null;
        } catch (JsonException) {
            return null;
        }
    }
}
