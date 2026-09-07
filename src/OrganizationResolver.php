<?php

declare(strict_types=1);

namespace AlexKassel\DevKit;

use JsonException;
use stdClass;

class OrganizationResolver
{
    /**
     * Resolve all recognized vendor organizations through multi-tiered discovery:
     * 1. Target package vendor (contextual zero-config inheritance)
     * 2. CLI options (comma-separated string or array)
     * 3. Configured organizations (config/dev-kit.php)
     * 4. Host root composer.json vendor discovery
     * 5. Local packages directory scan (packages/*)
     *
     * @param  string  $root  Root path of the host workspace
     * @param  string|null  $targetPackage  Optional target package (e.g. 'acme/billing')
     * @param  string|array<int|string, mixed>|null  $cliOrganizations  Optional organizations passed via CLI
     * @param  array<int|string, mixed>|null  $configuredOrganizations  Optional config dev-kit.organizations
     * @return list<string> Sorted unique list of valid vendor organization slugs
     */
    public function resolve(
        string $root,
        ?string $targetPackage = null,
        string|array|null $cliOrganizations = null,
        ?array $configuredOrganizations = null
    ): array {
        $organizations = [];

        // Tier 1: Target package vendor inheritance
        if ($targetPackage !== null && str_contains($targetPackage, '/')) {
            [$targetVendor] = explode('/', trim($targetPackage, '/\\ '), 2);
            if ($this->isValidVendor($targetVendor)) {
                $organizations[] = strtolower($targetVendor);
            }
        }

        // Tier 2: CLI overrides (supports comma-separated string or array)
        if ($cliOrganizations !== null) {
            $parsedCli = $this->parseCliOrganizations($cliOrganizations);
            foreach ($parsedCli as $org) {
                if ($this->isValidVendor($org)) {
                    $organizations[] = strtolower($org);
                }
            }
        }

        // Tier 3: Explicit configuration
        if ($configuredOrganizations !== null) {
            foreach ($configuredOrganizations as $org) {
                if (is_string($org)) {
                    foreach ($this->splitCommaSeparated($org) as $piece) {
                        if ($this->isValidVendor($piece)) {
                            $organizations[] = strtolower($piece);
                        }
                    }
                }
            }
        }

        // Tier 4: Host application composer.json vendor discovery
        $hostVendor = $this->discoverHostVendor($root);
        if ($hostVendor !== null && $this->isValidVendor($hostVendor)) {
            $organizations[] = strtolower($hostVendor);
        }

        // Tier 5: Local packages directory discovery
        $localVendors = $this->discoverLocalVendors($root);
        foreach ($localVendors as $vendor) {
            if ($this->isValidVendor($vendor)) {
                $organizations[] = strtolower($vendor);
            }
        }

        $unique = array_values(array_unique($organizations));
        sort($unique);

        return $unique;
    }

    /**
     * @param  string|array<int|string, mixed>  $input
     * @return list<string>
     */
    public function parseCliOrganizations(string|array $input): array
    {
        $result = [];

        if (is_string($input)) {
            $input = $this->splitCommaSeparated($input);
        }

        foreach ($input as $item) {
            if (is_string($item)) {
                foreach ($this->splitCommaSeparated($item) as $part) {
                    $cleaned = trim($part);
                    if ($cleaned !== '') {
                        $result[] = $cleaned;
                    }
                }
            }
        }

        return $result;
    }

    public function isValidVendor(string $vendor): bool
    {
        return preg_match('/^[a-z0-9]+(?:[_.-][a-z0-9]+)*$/iD', $vendor) === 1;
    }

    /**
     * Resolves a package name, prepending vendor if omitted (e.g. 'dev-kit' -> 'alex-kassel/dev-kit').
     *
     * @param  string  $root  Root path of the host workspace
     * @param  string  $packageName  Package name (e.g. 'dev-kit' or 'alex-kassel/dev-kit')
     * @param  string|array<int|string, mixed>|null  $cliOrganizations  Optional CLI organizations
     * @param  array<int|string, mixed>|null  $configuredOrganizations  Optional config organizations
     */
    public function resolvePackageName(
        string $root,
        string $packageName,
        string|array|null $cliOrganizations = null,
        ?array $configuredOrganizations = null
    ): string {
        $trimmed = trim($packageName, '/\\ ');
        if (str_contains($trimmed, '/')) {
            return $trimmed;
        }

        if ($trimmed === '') {
            return '';
        }

        $orgs = $this->resolve($root, null, $cliOrganizations, $configuredOrganizations);
        $vendor = $orgs[0] ?? 'alex-kassel';

        return $vendor.'/'.$trimmed;
    }

    /**
     * @return list<string>
     */
    private function splitCommaSeparated(string $input): array
    {
        $parts = preg_split('/[\s,]+/', trim($input)) ?: [];

        return array_values(array_filter($parts, fn (string $part): bool => trim($part) !== ''));
    }

    private function discoverHostVendor(string $root): ?string
    {
        $composerPath = rtrim($root, '/\\').DIRECTORY_SEPARATOR.'composer.json';
        if (! file_exists($composerPath) || ! is_readable($composerPath)) {
            return null;
        }

        $contents = @file_get_contents($composerPath);
        if ($contents === false) {
            return null;
        }

        try {
            $manifest = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
            if ($manifest instanceof stdClass && isset($manifest->name) && is_string($manifest->name)) {
                if (str_contains($manifest->name, '/')) {
                    [$vendor] = explode('/', $manifest->name, 2);
                    // Filter out generic skeleton vendors like 'laravel' unless desired
                    if (! in_array(strtolower($vendor), ['laravel'], true)) {
                        return $vendor;
                    }
                }
            }
        } catch (JsonException) {
            return null;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function discoverLocalVendors(string $root): array
    {
        $packagesDir = rtrim($root, '/\\').DIRECTORY_SEPARATOR.'packages';
        if (! is_dir($packagesDir)) {
            return [];
        }

        $entries = @scandir($packagesDir) ?: [];
        $vendors = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $fullPath = $packagesDir.DIRECTORY_SEPARATOR.$entry;
            if (is_dir($fullPath)) {
                $vendors[] = $entry;
            }
        }

        return $vendors;
    }
}
