<?php

declare(strict_types=1);

namespace AlexKassel\DevKit;

use Illuminate\Support\Facades\Process;
use JsonException;
use RuntimeException;

class PackageScaffolder
{
    /**
     * @return array{schema_version: int, status: string, package: string, path: string, archetype: string, dry_run: bool, git: string, root_registration: string, files_count: int, files: list<string>}
     */
    public function scaffold(
        string $root,
        string $package,
        string $archetype = 'library',
        bool $initGit = false,
        bool $registerInRoot = false,
        bool $dryRun = false
    ): array {
        $root = realpath($root);
        if ($root === false) {
            throw new RuntimeException('Host directory does not exist.');
        }

        if (! in_array($archetype, ['library', 'engine', 'domain'], true)) {
            throw new RuntimeException("Invalid archetype '{$archetype}'. Supported archetypes: library, engine, domain");
        }

        $rawPackage = trim($package, '/\\ ');
        $normalizedPackage = str_replace('\\', '/', $rawPackage);

        if (! preg_match('~^[a-z0-9]+(?:[_.-][a-z0-9]+)*/[a-z0-9]+(?:[_.-][a-z0-9]+)*$~Di', $normalizedPackage)) {
            throw new RuntimeException("Invalid package identifier '{$rawPackage}'. Must be in 'vendor/package-name' format.");
        }

        $parts = explode('/', $normalizedPackage);
        $vendor = strtolower($parts[0]);
        $packageName = strtolower($parts[1]);

        $packageDir = $root.DIRECTORY_SEPARATOR.'packages'.DIRECTORY_SEPARATOR.$vendor.DIRECTORY_SEPARATOR.$packageName;
        $relPackageDir = "packages/{$vendor}/{$packageName}";

        if (is_dir($packageDir) && count(scandir($packageDir) ?: []) > 2) {
            throw new RuntimeException("Package directory '{$relPackageDir}' already exists and is not empty.");
        }

        $vendorNamespace = $this->toPascalCase($vendor);
        $packageNamespace = $this->toPascalCase($packageName);
        $fullNamespace = "{$vendorNamespace}\\{$packageNamespace}";

        $filesToCreate = $this->generateTemplates($vendor, $packageName, $fullNamespace, $packageNamespace, $archetype);

        $createdFiles = [];

        if (! $dryRun) {
            if (! is_dir($packageDir) && ! @mkdir($packageDir, 0777, true) && ! is_dir($packageDir)) {
                throw new RuntimeException("Cannot create package directory: {$packageDir}");
            }

            foreach ($filesToCreate as $relFile => $content) {
                $absFilePath = $packageDir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relFile);
                FileIO::write($absFilePath, $content);
                $createdFiles[] = "{$relPackageDir}/{$relFile}";
            }

            $gitStatus = 'skipped';
            if ($initGit) {
                $gitDir = $packageDir.DIRECTORY_SEPARATOR.'.git';
                if (! is_dir($gitDir)) {
                    $result = Process::path($packageDir)->run(['git', 'init', '-b', 'main']);
                    $gitStatus = $result->successful() ? 'initialized' : 'failed';
                } else {
                    $gitStatus = 'already_initialized';
                }
            }

            $rootRegStatus = 'skipped';
            if ($registerInRoot) {
                $rootRegStatus = $this->registerInRootComposer($root, "{$vendor}/{$packageName}");
            }
        } else {
            foreach (array_keys($filesToCreate) as $relFile) {
                $createdFiles[] = "{$relPackageDir}/{$relFile}";
            }
            $gitStatus = $initGit ? 'dry_run_git_init' : 'skipped';
            $rootRegStatus = $registerInRoot ? 'dry_run_register' : 'skipped';
        }

        return [
            'schema_version' => 1,
            'status' => 'created',
            'package' => "{$vendor}/{$packageName}",
            'path' => $relPackageDir,
            'archetype' => $archetype,
            'dry_run' => $dryRun,
            'git' => $gitStatus,
            'root_registration' => $rootRegStatus,
            'files_count' => count($createdFiles),
            'files' => $createdFiles,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function generateTemplates(
        string $vendor,
        string $packageName,
        string $fullNamespace,
        string $packageNamespace,
        string $archetype
    ): array {
        $year = date('Y');
        $author = $this->resolveAuthor($vendor);
        $files = [];

        // 1. composer.json
        $keywords = ['laravel', 'package'];
        if ($archetype === 'engine') {
            $keywords[] = 'engine';
        } elseif ($archetype === 'domain') {
            $keywords[] = 'domain';
        }

        $composerData = [
            'name' => "{$vendor}/{$packageName}",
            'description' => "{$packageNamespace} {$archetype} package for Laravel ecosystem.",
            'type' => 'library',
            'license' => 'MIT',
            'keywords' => $keywords,
            'authors' => [
                $author,
            ],
            'require' => [
                'php' => '^8.2 || ^8.3 || ^8.4',
                'illuminate/support' => '^11.0 || ^12.0 || ^13.0',
            ],
            'autoload' => [
                'psr-4' => [
                    "{$fullNamespace}\\" => 'src/',
                ],
            ],
            'autoload-dev' => [
                'psr-4' => [
                    "{$fullNamespace}\\Tests\\" => 'tests/',
                ],
            ],
            'extra' => [
                'laravel' => [
                    'providers' => [
                        "{$fullNamespace}\\{$packageNamespace}ServiceProvider",
                    ],
                ],
            ],
            'minimum-stability' => 'dev',
            'prefer-stable' => true,
        ];

        $files['composer.json'] = json_encode($composerData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

        // 2. src/ServiceProvider.php
        $files["src/{$packageNamespace}ServiceProvider.php"] = <<<PHP
<?php

declare(strict_types=1);

namespace {$fullNamespace};

use Illuminate\Support\ServiceProvider;

final class {$packageNamespace}ServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind contracts and domain services
    }

    public function boot(): void
    {
        // Boot lifecycle listeners or configurations
    }
}

PHP;

        // 3. tests/bootstrap.php
        $files['tests/bootstrap.php'] = <<<PHP
<?php

declare(strict_types=1);

\$candidates = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../../../vendor/autoload.php',
];

\$autoloader = null;
foreach (\$candidates as \$candidate) {
    if (file_exists(\$candidate)) {
        \$autoloader = require \$candidate;
        break;
    }
}

if (\$autoloader === null) {
    throw new RuntimeException('Composer autoloader not found.');
}

\$autoloader->addPsr4('{$fullNamespace}\\\\Tests\\\\', __DIR__);

PHP;

        // 4. tests/TestCase.php
        $files['tests/TestCase.php'] = <<<PHP
<?php

declare(strict_types=1);

namespace {$fullNamespace}\\Tests;

use {$fullNamespace}\\{$packageNamespace}ServiceProvider;
use Orchestra\\Testbench\\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders(\$app): array
    {
        return [
            {$packageNamespace}ServiceProvider::class,
        ];
    }
}

PHP;

        // 5. tests/Unit/ServiceProviderTest.php
        $files['tests/Unit/ServiceProviderTest.php'] = <<<PHP
<?php

declare(strict_types=1);

namespace {$fullNamespace}\\Tests\\Unit;

use {$fullNamespace}\\{$packageNamespace}ServiceProvider;
use {$fullNamespace}\\Tests\\TestCase;
use PHPUnit\\Framework\\Attributes\\Test;

final class ServiceProviderTest extends TestCase
{
    #[Test]
    public function it_registers_service_provider_cleanly(): void
    {
        \$provider = \$this->app->getProvider({$packageNamespace}ServiceProvider::class);
        \$this->assertInstanceOf({$packageNamespace}ServiceProvider::class, \$provider);
    }
}

PHP;

        // 6. phpunit.xml
        $files['phpunit.xml'] = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/11.0/phpunit.xsd"
         bootstrap="tests/bootstrap.php"
         colors="true"
         cacheDirectory=".phpunit.cache">
    <testsuites>
        <testsuite name="Unit">
            <directory suffix="Test.php">tests/Unit</directory>
        </testsuite>
        <testsuite name="Feature">
            <directory suffix="Test.php">tests/Feature</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory suffix=".php">src</directory>
        </include>
    </source>
</phpunit>

XML;

        // 7. phpstan.neon
        $files['phpstan.neon'] = <<<'NEON'
parameters:
    level: 8
    paths:
        - src
    tmpDir: build/phpstan

NEON;

        // 8. .gitignore
        $files['.gitignore'] = <<<'GITIGNORE'
/vendor/
/.phpunit.cache/
/.phpunit.result.cache
/build/
/.audit/
/.audits/
.env
*.lock

GITIGNORE;

        // 9. .gitattributes
        $files['.gitattributes'] = <<<'GITATTRIBUTES'
* text=auto eol=lf

/tests export-ignore
/.github export-ignore
/phpunit.xml export-ignore
/phpstan.neon export-ignore
/.gitignore export-ignore
/.gitattributes export-ignore
/.audit export-ignore
/.audits export-ignore

GITATTRIBUTES;

        // 10. LICENSE
        $files['LICENSE'] = <<<LICENSE
The MIT License (MIT)

Copyright (c) {$year} {$author['name']}

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.

LICENSE;

        // 11. CHANGELOG.md
        $files['CHANGELOG.md'] = <<<CHANGELOG
# Changelog

All notable changes to `{$vendor}/{$packageName}` will be documented in this file.

## v0.1.0 - Unreleased

- Initial package scaffolding and foundation architecture.

CHANGELOG;

        // 12. README.md
        $files['README.md'] = <<<README
<h1 align="center">📦 {$packageNamespace}</h1>

<p align="center">
  <strong>{$packageNamespace} package for Laravel ecosystem</strong>
</p>

<p align="center">
  <a href="#installation">Installation</a> •
  <a href="#usage">Usage</a> •
  <a href="#testing">Testing</a> •
  <a href="CHANGELOG.md">Changelog</a>
</p>

<p align="center">
  <a href="https://packagist.org/packages/{$vendor}/{$packageName}"><img src="https://img.shields.io/packagist/v/{$vendor}/{$packageName}?color=f59e0b&logo=packagist&logoColor=white" alt="Latest Version"></a>
  <a href="https://laravel.com"><img src="https://img.shields.io/badge/Laravel-11%20%7C%2012%20%7C%2013-ff2d20?logo=laravel&logoColor=white" alt="Laravel Support"></a>
  <a href="https://php.net"><img src="https://img.shields.io/badge/PHP-8.2+-777bb4?logo=php&logoColor=white" alt="PHP Support"></a>
  <a href="phpstan.neon"><img src="https://img.shields.io/badge/PHPStan-Level%208-8b5cf6?logo=php&logoColor=white" alt="PHPStan Level 8"></a>
</p>

---

## Key Features

* **Feature One:** Clean service provider integration.
* **Feature Two:** Type-safe domain architecture.

---

## Requirements

* **PHP:** 8.2+ (tested on 8.2, 8.3, 8.4)
* **Laravel Framework:** 11.x | 12.x | 13.x

---

## Installation

Install the package via Composer:

```bash
composer require {$vendor}/{$packageName}
```

---

## Usage

```php
use {$fullNamespace}\\{$packageNamespace}ServiceProvider;

// Implementation example
```

---

## Testing

```bash
php artisan test -c packages/{$vendor}/{$packageName}/phpunit.xml
```

---

## Changelog

Please see [CHANGELOG.md](CHANGELOG.md) for more information on what has changed recently.

## Security Vulnerabilities

Please review [Security Policies](https://github.com/{$vendor}/{$packageName}/security/policy) on how to report vulnerabilities.

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.

README;

        return $files;
    }

    private function registerInRootComposer(string $root, string $package): string
    {
        $composerPath = $root.DIRECTORY_SEPARATOR.'composer.json';
        if (! file_exists($composerPath)) {
            return 'missing_root_composer';
        }

        $lockPath = $root.DIRECTORY_SEPARATOR.'.composer-manifest.lock';

        return FileLock::run($lockPath, function () use ($composerPath, $package): string {
            $content = @file_get_contents($composerPath);
            if ($content === false) {
                return 'unreadable_root_composer';
            }

            try {
                /** @var array<string, mixed>|null $json */
                $json = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return 'invalid_root_composer';
            }

            if (! is_array($json)) {
                return 'invalid_root_composer';
            }

            /** @var array<string, string> $require */
            $require = isset($json['require']) && is_array($json['require']) ? $json['require'] : [];
            if (isset($require[$package])) {
                return 'already_registered';
            }

            $require[$package] = '@dev';
            $json['require'] = $require;

            try {
                $encoded = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return 'invalid_root_composer';
            }

            FileIO::write($composerPath, $encoded."\n", $content);

            return 'registered';
        });
    }

    /**
     * @return array{name: string, role: string, email?: string}
     */
    private function resolveAuthor(string $vendor): array
    {
        $nameResult = Process::run(['git', 'config', 'user.name']);
        $name = trim($nameResult->output());

        if ($name === '') {
            $name = $this->toPascalCase($vendor);
        }

        $emailResult = Process::run(['git', 'config', 'user.email']);
        $email = trim($emailResult->output());

        $author = [
            'name' => $name,
            'role' => 'Developer',
        ];

        if ($email !== '') {
            $author['email'] = $email;
        }

        return $author;
    }

    private function toPascalCase(string $input): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $input)));
    }
}
