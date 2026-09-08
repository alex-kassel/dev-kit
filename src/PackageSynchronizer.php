<?php

declare(strict_types=1);

namespace AlexKassel\DevKit;

use Illuminate\Support\Facades\File;
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

        $lockPath = $root.DIRECTORY_SEPARATOR.'.composer-manifest.lock';

        return FileLock::run($lockPath, function () use ($root, $clean, $filter, $dryRun): array {
            return $this->doSync($root, $clean, $filter, $dryRun);
        });
    }

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
    private function doSync(string $root, bool $clean, ?string $filter, bool $dryRun): array
    {
        $rootComposerPath = $root.DIRECTORY_SEPARATOR.'composer.json';
        $composerContents = FileIO::read($rootComposerPath);
        try {
            /** @var array<string, mixed>|null $composerData */
            $composerData = json_decode($composerContents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new RuntimeException('Failed to parse root composer.json: '.$e->getMessage(), 0, $e);
        }

        if (! is_array($composerData)) {
            throw new RuntimeException('Root composer.json is not an array.');
        }

        /** @var array<string, string> $currentRequire */
        $currentRequire = isset($composerData['require']) && is_array($composerData['require'])
            ? $composerData['require']
            : [];

        /** @var array<string, string> $currentRequireDev */
        $currentRequireDev = isset($composerData['require-dev']) && is_array($composerData['require-dev'])
            ? $composerData['require-dev']
            : [];

        $existingDevPackages = [];
        $existingDevPackagesDev = [];
        $systemRequire = [];
        $systemRequireDev = [];

        foreach ($currentRequire as $pkgName => $pkgVersion) {
            if ($pkgVersion === '@dev') {
                $existingDevPackages[$pkgName] = $pkgVersion;
            } else {
                $systemRequire[$pkgName] = $pkgVersion;
            }
        }

        foreach ($currentRequireDev as $pkgName => $pkgVersion) {
            if ($pkgVersion === '@dev') {
                $existingDevPackagesDev[$pkgName] = $pkgVersion;
            } else {
                $systemRequireDev[$pkgName] = $pkgVersion;
            }
        }

        $discoveredPackages = $this->discoverPackages($root);

        $packagesToRegister = [];
        $packagesToRegisterDev = [];
        if (! $clean) {
            foreach ($discoveredPackages as $name => $meta) {
                if ($filter !== null) {
                    if (! fnmatch($filter, $name) && ! fnmatch($filter, $meta['path'])) {
                        continue;
                    }
                }

                $isDevDependency = (isset($currentRequireDev[$name]) || isset($existingDevPackagesDev[$name]))
                    && ! isset($currentRequire[$name]);

                if ($isDevDependency) {
                    $packagesToRegisterDev[$name] = '@dev';
                    unset($systemRequireDev[$name]);
                } else {
                    $packagesToRegister[$name] = '@dev';
                    unset($systemRequire[$name]);
                    if (isset($systemRequireDev[$name])) {
                        unset($systemRequireDev[$name]);
                    }
                }
            }
        }

        $allRegistered = array_merge($packagesToRegister, $packagesToRegisterDev);
        $allExisting = array_merge($existingDevPackages, $existingDevPackagesDev);

        /** @var list<string> $added */
        $added = array_keys(array_diff_key($allRegistered, $allExisting));
        /** @var list<string> $removed */
        $removed = array_keys(array_diff_key($allExisting, $allRegistered));
        /** @var list<string> $retained */
        $retained = array_keys(array_intersect_key($allRegistered, $allExisting));

        $newRequire = $systemRequire;
        ksort($packagesToRegister);
        foreach ($packagesToRegister as $pkgName => $version) {
            $newRequire[$pkgName] = $version;
        }

        $newRequireDev = $systemRequireDev;
        ksort($packagesToRegisterDev);
        foreach ($packagesToRegisterDev as $pkgName => $version) {
            $newRequireDev[$pkgName] = $version;
        }

        $composerData['require'] = $newRequire;
        if (isset($composerData['require-dev']) || $newRequireDev !== []) {
            $composerData['require-dev'] = $newRequireDev;
        }

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
            FileIO::write($rootComposerPath, $encoded."\n", $composerContents);
        }

        return [
            'status' => 'success',
            'dry_run' => $dryRun,
            'clean_mode' => $clean,
            'filter' => $filter,
            'total_discovered_on_disk' => count($discoveredPackages),
            'registered_count' => count($allRegistered),
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

        if (! File::isDirectory($packagesDir)) {
            return [];
        }

        $vendorDirs = File::directories($packagesDir);
        foreach ($vendorDirs as $fullVendorDir) {
            $vDir = basename($fullVendorDir);
            $pkgDirs = File::directories($fullVendorDir);

            foreach ($pkgDirs as $fullPkgDir) {
                $pDir = basename($fullPkgDir);
                $pkgComposerFile = $fullPkgDir.DIRECTORY_SEPARATOR.'composer.json';

                if (File::exists($pkgComposerFile)) {
                    $declaredName = "{$vDir}/{$pDir}";
                    try {
                        /** @var array<string, mixed>|null $pkgData */
                        $pkgData = File::json($pkgComposerFile);
                        if (is_array($pkgData) && isset($pkgData['name']) && is_string($pkgData['name'])) {
                            $declaredName = $pkgData['name'];
                        }
                    } catch (\Throwable) {
                        // fallback to vendor/package
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
