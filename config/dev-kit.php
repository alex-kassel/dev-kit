<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Recognized Organizations / Vendor Namespaces
    |--------------------------------------------------------------------------
    |
    | DevKit automatically recognizes:
    | 1. The vendor of the package being cloned or operated on.
    | 2. Any vendors discovered in your local `packages/` directory.
    | 3. The vendor of your host application from root `composer.json`.
    |
    | You can declare additional trusted partner organizations or vendor
    | namespaces here (comma-separated strings or array list):
    |
    */
    'organizations' => [
        // 'acme',
        // 'partner-org',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Git Source URL Pattern
    |--------------------------------------------------------------------------
    |
    | Used by `php artisan pkg:clone` to determine remote repository URLs.
    | Placeholders `{vendor}` and `{package}` will be dynamically replaced.
    |
    */
    'default_source_pattern' => 'git@github.com:{vendor}/{package}.git',

    /*
    |--------------------------------------------------------------------------
    | Explicit Source Overrides
    |--------------------------------------------------------------------------
    |
    | Specific repository URLs for packages that don't match default pattern:
    |
    */
    'sources' => [
        // 'acme/special-pkg' => 'git@gitlab.com:acme/special-pkg.git',
    ],

    /*
    |--------------------------------------------------------------------------
    | Process Execution Timeouts & Resource Limits
    |--------------------------------------------------------------------------
    |
    | Maximum execution times (in seconds) for background processes and verification
    | suites, plus memory limit overrides for static analysis (PHPStan).
    |
    */
    'timeouts' => [
        'verification' => 120.0,
        'composer_update' => 300.0,
        'composer_update_recursive' => 600.0,
        'git_operation' => 60.0,
    ],

    'memory_limit' => '1G',
];
