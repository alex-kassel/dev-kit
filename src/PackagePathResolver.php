<?php

declare(strict_types=1);

namespace AlexKassel\DevKit;

use RuntimeException;

class PackagePathResolver
{
    public function resolve(string $root, string $package): string
    {
        $name = str_starts_with($package, 'packages/') ? substr($package, 9) : $package;
        if (! preg_match('~^[a-z0-9]+(?:[_.-][a-z0-9]+)*/[a-z0-9]+(?:[_.-][a-z0-9]+)*$~D', $name)) {
            throw new RuntimeException('Invalid Composer package name: '.$package);
        }
        $root = realpath($root);
        if ($root === false) {
            throw new RuntimeException('Host directory does not exist.');
        }
        [$vendor] = explode('/', $name);
        $resolved = $root;
        foreach (['packages', 'packages/'.$vendor, 'packages/'.$name] as $relative) {
            $path = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            clearstatcache(true, $path);
            $resolved = realpath($path);
            if (! is_dir($path) || $resolved === false) {
                throw new RuntimeException("Package directory not found for '{$package}'. Checked: packages/{$name}");
            }
            $expected = str_replace('\\', '/', $path);
            $actual = str_replace('\\', '/', $resolved);
            $same = PHP_OS_FAMILY === 'Windows' ? strcasecmp($expected, $actual) === 0 : $expected === $actual;
            if (is_link($path) || ! $same) {
                throw new RuntimeException('Refusing a linked package path: '.$relative);
            }
        }

        return $resolved;
    }
}
