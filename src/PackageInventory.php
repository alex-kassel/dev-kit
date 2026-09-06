<?php

namespace AlexKassel\DevKit;

use JsonException;
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
        if (! file_exists($packageRoot)) {
            return [];
        }
        if (! is_dir($packageRoot) || ! is_readable($packageRoot)) {
            throw new RuntimeException('Cannot read the packages directory.');
        }

        $packages = [];
        $seen = [];
        foreach ($this->directories($packageRoot) as $vendorDirectory) {
            foreach ($this->directories($vendorDirectory) as $directory) {
                $manifestPath = $directory.'/composer.json';
                $relative = 'packages/'.basename($vendorDirectory).'/'.basename($directory);
                $contents = is_file($manifestPath) && is_readable($manifestPath) ? @file_get_contents($manifestPath) : false;
                if ($contents === false) {
                    throw new RuntimeException('Cannot read '.$relative.'/composer.json');
                }
                try {
                    $manifest = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException $exception) {
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

    /** @return list<string> */
    private function directories(string $root): array
    {
        $entries = @scandir($root);
        if ($entries === false) {
            throw new RuntimeException('Cannot list directory: '.$root);
        }
        $directories = [];
        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_dir($root.'/'.$entry)) {
                $directories[] = $root.'/'.$entry;
            }
        }

        return $directories;
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
