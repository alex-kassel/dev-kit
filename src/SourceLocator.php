<?php

namespace AlexKassel\DevKit;

use RuntimeException;

class SourceLocator
{
    public function resolve(string $package, mixed $sources, ?string $defaultPattern = null): string
    {
        if (! preg_match('~^[a-z0-9]+(?:[_.-][a-z0-9]+)*/[a-z0-9]+(?:[_.-][a-z0-9]+)*$~D', $package)) {
            throw new RuntimeException('Invalid Composer package name: '.$package);
        }

        $source = is_array($sources) ? ($sources[$package] ?? null) : null;

        if ((! is_string($source) || $source === '') && $defaultPattern !== null && $defaultPattern !== '') {
            [$vendor, $name] = explode('/', $package, 2);
            $source = str_replace(['{vendor}', '{package}'], [$vendor, $name], $defaultPattern);
        }

        if (! is_string($source) || $source === '' || str_contains($source, "\n") || str_contains($source, "\r")) {
            throw new RuntimeException('No valid source configured for '.$package.' in dev-kit.sources.');
        }

        if (! preg_match('~^(https://|ssh://|git@[a-zA-Z0-9.-]+:)~', $source) && ! is_dir($source)) {
            throw new RuntimeException('Source must be an HTTPS/SSH Git URL or an existing local Git directory.');
        }

        return $source;
    }
}
