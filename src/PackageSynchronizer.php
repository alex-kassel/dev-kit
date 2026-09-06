<?php

namespace AlexKassel\DevKit;

use JsonException;
use RuntimeException;

class PackageSynchronizer
{
    /**
     * @return array{
     *     status: string,
     *     dry_run: bool,
     *     clean_mode: bool,
     *     filter: ?string,
     *     total_discovered_on_disk: int,
     *     registered_count: int,
     *     added: list<string>,
     *     removed: list<string>,
     *     retained: list<string>
     * }
     */
    public function sync(string $root, bool $clean = false, ?string $filter = null, bool $dryRun = false): array
    {
        $root = realpath($root);
        if ($root === false) {
            throw new RuntimeException('Host directory does not exist.');
        }

        $rootComposerPath = $root.DIRECTORY_SEPARATOR.'composer.json';
        if (! file_exists($rootComposerPath)) {
            throw new RuntimeException('Root composer.json not found.');
        }

        $composerContents = @file_get_contents($rootComposerPath);
        if ($composerContents === false) {
            throw new RuntimeException('Cannot read root composer.json.');
        }

        try {
            /** @var array<string, mixed>|null $composerData */
            $composerData = json_decode($composerContents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Failed to parse root composer.json: '.$e->getMessage(), 0, $e);
        }

        if (! is_array($composerData)) {
            throw new RuntimeException('Root composer.json is not an array.');
        }

        /** @var array<string, string> $currentRequire */
        $currentRequire = isset($composerData['require']) && is_array($composerData['require'])
            ? $composerData['require']
            : [];

        $existingDevPackages = [];
        $systemRequire = [];

        foreach ($currentRequire as $pkgName => $pkgVersion) {
            if ($pkgVersion === '@dev') {
                $existingDevPackages[$pkgName] = $pkgVersion;
            } else {
                $systemRequire[$pkgName] = $pkgVersion;
            }
        }

        $discoveredPackages = $this->discoverPackages($root);

        $packagesToRegister = [];
        if (! $clean) {
            foreach ($discoveredPackages as $name => $meta) {
                if ($filter !== null) {
                    if (! fnmatch($filter, $name) && ! fnmatch($filter, $meta['path'])) {
                        continue;
                    }
                }
                $packagesToRegister[$name] = '@dev';
            }
        }

        /** @var list<string> $added */
        $added = array_keys(array_diff_key($packagesToRegister, $existingDevPackages));
        /** @var list<string> $removed */
        $removed = array_keys(array_diff_key($existingDevPackages, $packagesToRegister));
        /** @var list<string> $retained */
        $retained = array_keys(array_intersect_key($packagesToRegister, $existingDevPackages));

        $newRequire = $systemRequire;
        ksort($packagesToRegister);
        foreach ($packagesToRegister as $pkgName => $version) {
            $newRequire[$pkgName] = $version;
        }

        $composerData['require'] = $newRequire;

        // Ensure path repository exists
        $hasPathRepo = false;
        if (isset($composerData['repositories']) && is_array($composerData['repositories'])) {
            foreach ($composerData['repositories'] as $repo) {
                if (is_array($repo) && isset($repo['url']) && ($repo['url'] === 'packages/*/*' || $repo['url'] === './packages/*/*')) {
                    $hasPathRepo = true;
                    break;
                }
            }
        }

        if (! $hasPathRepo) {
            if (! isset($composerData['repositories']) || ! is_array($composerData['repositories'])) {
                $composerData['repositories'] = [];
            }
            $composerData['repositories'][] = [
                'type' => 'path',
                'url' => 'packages/*/*',
            ];
        }

        if (! $dryRun) {
            $encoded = json_encode($composerData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                throw new RuntimeException('Failed to encode updated composer.json.');
            }
            file_put_contents($rootComposerPath, $encoded."\n");
        }

        return [
            'status' => 'success',
            'dry_run' => $dryRun,
            'clean_mode' => $clean,
            'filter' => $filter,
            'total_discovered_on_disk' => count($discoveredPackages),
            'registered_count' => count($packagesToRegister),
            'added' => $added,
            'removed' => $removed,
            'retained' => $retained,
        ];
    }

    /**
     * @return array<string, array{name: string, path: string, vendor: string, package: string}>
     */
    private function discoverPackages(string $root): array
    {
        $discovered = [];
        $packagesDir = $root.DIRECTORY_SEPARATOR.'packages';

        if (! is_dir($packagesDir)) {
            return [];
        }

        $vendorDirs = scandir($packagesDir) ?: [];
        foreach ($vendorDirs as $vDir) {
            if ($vDir === '.' || $vDir === '..') {
                continue;
            }
            $fullVendorDir = $packagesDir.DIRECTORY_SEPARATOR.$vDir;
            if (! is_dir($fullVendorDir)) {
                continue;
            }

            $pkgDirs = scandir($fullVendorDir) ?: [];
            foreach ($pkgDirs as $pDir) {
                if ($pDir === '.' || $pDir === '..') {
                    continue;
                }
                $fullPkgDir = $fullVendorDir.DIRECTORY_SEPARATOR.$pDir;
                $pkgComposerFile = $fullPkgDir.DIRECTORY_SEPARATOR.'composer.json';

                if (is_dir($fullPkgDir) && file_exists($pkgComposerFile)) {
                    $pkgContents = @file_get_contents($pkgComposerFile);
                    $declaredName = "{$vDir}/{$pDir}";
                    if ($pkgContents !== false) {
                        try {
                            /** @var array<string, mixed>|null $pkgData */
                            $pkgData = json_decode($pkgContents, true, 512, JSON_THROW_ON_ERROR);
                            if (is_array($pkgData) && isset($pkgData['name']) && is_string($pkgData['name'])) {
                                $declaredName = $pkgData['name'];
                            }
                        } catch (JsonException) {
                            // fallback to vendor/package
                        }
                    }

                    $relPath = "packages/{$vDir}/{$pDir}";
                    $discovered[$declaredName] = [
                        'name' => $declaredName,
                        'path' => $relPath,
                        'vendor' => $vDir,
                        'package' => $pDir,
                    ];
                }
            }
        }

        return $discovered;
    }
}
