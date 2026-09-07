<?php

declare(strict_types=1);

namespace AlexKassel\DevKit;

use RuntimeException;

class PackagePathResolver
{
    public function resolve(string $root, string $package): string
    {
        $rawPackage = trim($package, '/\\ ');
        $candidates = [
            $root.DIRECTORY_SEPARATOR.$rawPackage,
            $root.DIRECTORY_SEPARATOR.'packages'.DIRECTORY_SEPARATOR.$rawPackage,
        ];

        foreach ($candidates as $candidate) {
            if (is_dir($candidate)) {
                return realpath($candidate) ?: $candidate;
            }
        }

        throw new RuntimeException("Package directory not found for '{$package}'. Checked: packages/{$rawPackage}");
    }
}
