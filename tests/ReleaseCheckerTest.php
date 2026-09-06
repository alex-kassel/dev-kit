<?php

namespace AlexKassel\DevKit\Tests;

use AlexKassel\DevKit\PackageVerifier;
use AlexKassel\DevKit\ReadmeValidator;
use AlexKassel\DevKit\ReleaseChecker;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

class ReleaseCheckerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/dev-kit-release-test-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $resolved = realpath($this->root);
        $parent = realpath(sys_get_temp_dir());
        if ($resolved === false || dirname($resolved) !== $parent || ! str_starts_with(basename($resolved), 'dev-kit-release-test-')) {
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

    public function test_check_reports_blocked_when_git_missing(): void
    {
        $pkgDir = $this->root.'/packages/alex-kassel/test-pkg';
        mkdir($pkgDir, 0777, true);
        file_put_contents($pkgDir.'/composer.json', json_encode(['name' => 'alex-kassel/test-pkg']));

        $verifier = $this->createMock(PackageVerifier::class);
        $verifier->method('verify')->willReturn([
            'package' => 'alex-kassel/test-pkg',
            'path' => 'packages/alex-kassel/test-pkg',
            'status' => 'passed',
            'summary' => ['passed' => 4, 'failed' => 0, 'skipped' => 0, 'not_configured' => 0],
            'checks' => [],
        ]);

        $readmeValidator = $this->createMock(ReadmeValidator::class);
        $readmeValidator->method('validate')->willReturn([
            'package' => 'alex-kassel/test-pkg',
            'path' => 'packages/alex-kassel/test-pkg',
            'status' => 'passed',
            'summary' => ['passed' => 7, 'failed' => 0],
            'checks' => [],
        ]);

        $checker = new ReleaseChecker($verifier, $readmeValidator);
        $result = $checker->check($this->root, 'alex-kassel/test-pkg');

        $this->assertSame('BLOCKED', $result['verdict']);
        $this->assertSame('failed', $result['checks']['git_repo']['status']);
        $this->assertSame('failed', $result['checks']['export_ignore']['status']);
    }

    public function test_check_detects_clean_git_and_gitattributes(): void
    {
        $pkgDir = $this->root.'/packages/alex-kassel/test-pkg';
        mkdir($pkgDir, 0777, true);
        file_put_contents($pkgDir.'/composer.json', json_encode(['name' => 'alex-kassel/test-pkg']));
        file_put_contents($pkgDir.'/.gitattributes', "* text=auto\n/tests export-ignore\n");

        // Initialize git
        exec("git -C \"{$pkgDir}\" init -b main");
        exec("git -C \"{$pkgDir}\" config user.email \"test@example.com\"");
        exec("git -C \"{$pkgDir}\" config user.name \"Test Runner\"");
        exec("git -C \"{$pkgDir}\" add .");
        exec("git -C \"{$pkgDir}\" commit -m \"initial commit\"");

        $verifier = $this->createMock(PackageVerifier::class);
        $verifier->method('verify')->willReturn([
            'package' => 'alex-kassel/test-pkg',
            'path' => 'packages/alex-kassel/test-pkg',
            'status' => 'passed',
            'summary' => ['passed' => 4, 'failed' => 0, 'skipped' => 0, 'not_configured' => 0],
            'checks' => [],
        ]);

        $readmeValidator = $this->createMock(ReadmeValidator::class);
        $readmeValidator->method('validate')->willReturn([
            'package' => 'alex-kassel/test-pkg',
            'path' => 'packages/alex-kassel/test-pkg',
            'status' => 'passed',
            'summary' => ['passed' => 7, 'failed' => 0],
            'checks' => [],
        ]);

        $checker = new ReleaseChecker($verifier, $readmeValidator);
        $result = $checker->check($this->root, 'alex-kassel/test-pkg');

        $this->assertSame('READY', $result['verdict']);
        $this->assertSame('passed', $result['checks']['git_repo']['status']);
        $this->assertSame('passed', $result['checks']['git_tree']['status']);
        $this->assertSame('passed', $result['checks']['export_ignore']['status']);
        $this->assertSame('passed', $result['checks']['code_quality']['status']);
        $this->assertSame('passed', $result['checks']['readme_compliance']['status']);
    }
}
