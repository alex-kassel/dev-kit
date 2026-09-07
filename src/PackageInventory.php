<?php

declare(strict_types=1);

namespace AlexKassel\DevKit;

use Illuminate\Support\Facades\File;
use RuntimeException;
use stdClass;

class PackageInventory
{
    /**
     * @param  array<string, array{path: string, version: ?string}>  $installed
     * @return list<array{name: string, path: string, owned: bool, installed: bool, linked: bool, installed_version: ?string}>
     */
    public function inspect(string $root, mixed $organizations, array $installed): array
    {
        if (! is_array($organizations) || ! array_is_list($organizations)) {
            throw new RuntimeException('dev-kit.organizations must be a list of Composer vendors.');
        }
        foreach ($organizations as $organization) {
            if (! is_string($organization) || ! preg_match('/^[a-z0-9]+(?:[_.-][a-z0-9]+)*$/D', $organization)) {
                throw new RuntimeException('dev-kit.organizations contains an invalid Composer vendor.');
            }
        }

        $packageRoot = rtrim($root, '/\\').'/packages';
        if (! File::exists($packageRoot)) {
            return [];
        }
        if (! File::isDirectory($packageRoot) || ! is_readable($packageRoot)) {
            throw new RuntimeException('Cannot read the packages directory.');
        }

        $packages = [];
        $seen = [];
        foreach (File::directories($packageRoot) as $vendorDirectory) {
            foreach (File::directories($vendorDirectory) as $directory) {
                $manifestPath = $directory.'/composer.json';
                $relative = 'packages/'.basename($vendorDirectory).'/'.basename($directory);
                if (! File::isFile($manifestPath) || ! is_readable($manifestPath)) {
                    throw new RuntimeException('Cannot read '.$relative.'/composer.json');
                }
                try {
                    $contents = File::get($manifestPath);
                    $manifest = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
                } catch (\Throwable $exception) {
                    throw new RuntimeException('Invalid JSON in '.$relative.'/composer.json: '.$exception->getMessage(), 0, $exception);
                }
                $name = $manifest instanceof stdClass ? ($manifest->name ?? null) : null;
                if (! is_string($name) || ! preg_match('~^[a-z0-9]+(?:[_.-][a-z0-9]+)*/[a-z0-9]+(?:[_.-][a-z0-9]+)*$~D', $name)) {
                    throw new RuntimeException('Invalid or missing Composer name in '.$relative.'/composer.json');
                }
                if (isset($seen[$name])) {
                    throw new RuntimeException('Duplicate package '.$name.' in '.$seen[$name].' and '.$relative);
                }
                $seen[$name] = $relative;
                $installation = $installed[$name] ?? null;
                $packages[] = [
                    'name' => $name,
                    'path' => $relative,
                    'owned' => in_array(explode('/', $name, 2)[0], $organizations, true),
                    'installed' => $installation !== null,
                    'linked' => $installation !== null && $this->samePath($directory, $installation['path']),
                    'installed_version' => $installation['version'] ?? null,
                ];
            }
        }
        usort($packages, fn (array $left, array $right): int => strcmp($left['name'], $right['name']));

        return $packages;
    }

    private function samePath(string $left, string $right): bool
    {
        $left = realpath($left);
        $right = realpath($right);
        if ($left === false || $right === false) {
            return false;
        }

        return PHP_OS_FAMILY === 'Windows' ? strcasecmp($left, $right) === 0 : $left === $right;
    }
}
