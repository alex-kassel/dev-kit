<?php

declare(strict_types=1);

namespace AlexKassel\DevKit\Tests;

use AlexKassel\DevKit\ReadmeValidator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

class ReadmeValidatorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/dev-kit-readme-test-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $resolved = realpath($this->root);
        $parent = realpath(sys_get_temp_dir());
        if ($resolved === false || dirname($resolved) !== $parent || ! str_starts_with(basename($resolved), 'dev-kit-readme-test-')) {
            throw new RuntimeException('Refusing cleanup outside the generated test directory.');
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, \FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            if ($entry->isDir() && ! $entry->isLink()) {
                @rmdir($entry->getPathname());
            } else {
                @chmod($entry->getPathname(), 0666);
                @unlink($entry->getPathname());
            }
        }
        @rmdir($resolved);
    }

    private function createPackage(string $name, ?string $readmeContent = null, bool $hasReleaseGate = false): void
    {
        $pkgDir = $this->root.'/packages/'.$name;
        mkdir($pkgDir, 0777, true);
        file_put_contents($pkgDir.'/composer.json', json_encode(['name' => $name]));

        if ($readmeContent !== null) {
            file_put_contents($pkgDir.'/README.md', $readmeContent);
        }

        if ($hasReleaseGate) {
            file_put_contents($pkgDir.'/RELEASE-GATE.md', "# Release Gate\nCommit: 1234567\n");
        }
    }

    private function standardReadme(): string
    {
        return <<<'MD'
<h1 align="center">My Package</h1>

<p align="center">
  <a href="#requirements">Requirements</a> •
  <a href="#installation">Installation</a> •
  <a href="#usage">Usage</a> •
  <a href="#testing">Testing</a> •
  <a href="#license">License</a>
</p>

<p align="center">
  <a href="https://packagist.org/packages/alex-kassel/test-pkg"><img src="https://img.shields.io/packagist/v/alex-kassel/test-pkg?color=f59e0b&logo=packagist&logoColor=white" alt="Latest Version"></a>
  <a href="https://laravel.com"><img src="https://img.shields.io/badge/Laravel-11%20%7C%2012%20%7C%2013-ff2d20?logo=laravel&logoColor=white" alt="Laravel Support"></a>
  <a href="https://php.net"><img src="https://img.shields.io/badge/PHP-8.2+-777bb4?logo=php&logoColor=white" alt="PHP Support"></a>
  <a href="phpstan.neon"><img src="https://img.shields.io/badge/PHPStan-Level%208-8b5cf6?logo=php&logoColor=white" alt="PHPStan Level 8"></a>
</p>

## Requirements
PHP 8.2+

## Installation
composer require alex-kassel/my-package

## Usage
Some usage instructions.

## Testing
Run tests.

## License
MIT
MD;
    }

    public function test_validate_passes_for_standard_compliant_readme(): void
    {
        $this->createPackage('alex-kassel/test-pkg', $this->standardReadme());

        $validator = new ReadmeValidator;
        $result = $validator->validate($this->root, 'alex-kassel/test-pkg');

        $this->assertSame('passed', $result['status']);
        $this->assertSame(8, $result['summary']['passed']);
        $this->assertSame(0, $result['summary']['failed']);
    }

    public function test_validate_fails_when_readme_missing(): void
    {
        $this->createPackage('alex-kassel/test-pkg', null);

        $validator = new ReadmeValidator;
        $result = $validator->validate($this->root, 'alex-kassel/test-pkg');

        $this->assertSame('failed', $result['status']);
        $this->assertSame('failed', $result['checks']['file_exists']['status']);
    }

    public function test_validate_detects_double_pipes_and_placeholders(): void
    {
        $badReadme = $this->standardReadme()."\n\nSome text with || double pipes\nUse <vendor>/<package> here";
        $this->createPackage('alex-kassel/test-pkg', $badReadme);

        $validator = new ReadmeValidator;
        $result = $validator->validate($this->root, 'alex-kassel/test-pkg');

        $this->assertSame('failed', $result['status']);
        $this->assertSame('failed', $result['checks']['badge_syntax']['status']);
        $this->assertSame('failed', $result['checks']['placeholders']['status']);
    }

    public function test_validate_fails_when_standard_badges_missing(): void
    {
        $noBadgesReadme = <<<'MD'
<h1 align="center">My Package</h1>

<p align="center">
  <a href="#requirements">Requirements</a> •
  <a href="#installation">Installation</a>
</p>

## Requirements
PHP 8.2+

## Installation
composer require alex-kassel/my-package

## Usage
Usage

## Testing
Test

## License
MIT
MD;
        $this->createPackage('alex-kassel/test-pkg', $noBadgesReadme);

        $validator = new ReadmeValidator;
        $result = $validator->validate($this->root, 'alex-kassel/test-pkg');

        $this->assertSame('failed', $result['status']);
        $this->assertSame('failed', $result['checks']['standard_badges']['status']);
        $this->assertStringContainsString('Missing canonical badge(s)', $result['checks']['standard_badges']['message']);
    }

    public function test_validate_audit_badge_integrity(): void
    {
        // Case 1: RELEASE-GATE exists but no badge
        $this->createPackage('alex-kassel/test-pkg', $this->standardReadme(), hasReleaseGate: true);
        $validator = new ReadmeValidator;
        $result = $validator->validate($this->root, 'alex-kassel/test-pkg');

        $this->assertSame('failed', $result['checks']['audit_badge']['status']);

        // Case 2: RELEASE-GATE exists and badge exists
        $withBadge = "<h1 align=\"center\">Title</h1>\n[![Audit Verified](https://img.shields.io/badge/Audit-Verified-10b981)](#)\n".$this->standardReadme();
        file_put_contents($this->root.'/packages/alex-kassel/test-pkg/README.md', $withBadge);

        $resultWithBadge = $validator->validate($this->root, 'alex-kassel/test-pkg');
        $this->assertSame('passed', $resultWithBadge['checks']['audit_badge']['status']);
    }
}
