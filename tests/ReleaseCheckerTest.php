<?php

declare(strict_types=1);

namespace AlexKassel\DevKit\Tests;

use AlexKassel\DevKit\PackageVerifier;
use AlexKassel\DevKit\ReadmeValidator;
use AlexKassel\DevKit\ReleaseChecker;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Process\Process;

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
        (new Process(['git', 'init', '-b', 'main'], $pkgDir))->mustRun();
        (new Process(['git', 'config', 'user.email', 'test@example.com'], $pkgDir))->mustRun();
        (new Process(['git', 'config', 'user.name', 'Test Runner'], $pkgDir))->mustRun();
        (new Process(['git', 'add', '.'], $pkgDir))->mustRun();
        (new Process(['git', 'commit', '-m', 'initial commit'], $pkgDir))->mustRun();

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

    public function test_audit_freshness_parses_canonical_release_gate_commit_format(): void
    {
        $pkgDir = $this->root.'/packages/alex-kassel/test-pkg';
        mkdir($pkgDir, 0777, true);
        file_put_contents($pkgDir.'/composer.json', json_encode(['name' => 'alex-kassel/test-pkg']));
        file_put_contents($pkgDir.'/.gitattributes', "* text=auto\n/tests export-ignore\n");

        (new Process(['git', 'init', '-b', 'main'], $pkgDir))->mustRun();
        (new Process(['git', 'config', 'user.email', 'test@example.com'], $pkgDir))->mustRun();
        (new Process(['git', 'config', 'user.name', 'Test Runner'], $pkgDir))->mustRun();
        (new Process(['git', 'add', '.'], $pkgDir))->mustRun();
        (new Process(['git', 'commit', '-m', 'feat: initial release'], $pkgDir))->mustRun();

        $commitProcess = new Process(['git', 'rev-parse', '--short=7', 'HEAD'], $pkgDir);
        $commitProcess->mustRun();
        $headCommit = trim($commitProcess->getOutput());

        file_put_contents($pkgDir.'/RELEASE-GATE.md', "# Release Gate\n- **Target Branch / Commit:** `main` (`{$headCommit}`)\n");

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

        $this->assertSame('passed', $result['checks']['audit_freshness']['status']);
        $this->assertStringContainsString('Audit certificate is up to date', $result['checks']['audit_freshness']['message']);
    }

    public function test_audit_freshness_flags_action_required_when_commit_hash_unparseable(): void
    {
        $pkgDir = $this->root.'/packages/alex-kassel/test-pkg';
        mkdir($pkgDir, 0777, true);
        file_put_contents($pkgDir.'/composer.json', json_encode(['name' => 'alex-kassel/test-pkg']));
        file_put_contents($pkgDir.'/.gitattributes', "* text=auto\n/tests export-ignore\n");
        file_put_contents($pkgDir.'/RELEASE-GATE.md', "# Release Gate without any commit hash\nSome text here\n");

        (new Process(['git', 'init', '-b', 'main'], $pkgDir))->mustRun();
        (new Process(['git', 'config', 'user.email', 'test@example.com'], $pkgDir))->mustRun();
        (new Process(['git', 'config', 'user.name', 'Test Runner'], $pkgDir))->mustRun();
        (new Process(['git', 'add', '.'], $pkgDir))->mustRun();
        (new Process(['git', 'commit', '-m', 'feat: initial commit'], $pkgDir))->mustRun();

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

        $this->assertSame('ACTION_REQUIRED', $result['verdict']);
        $this->assertSame('action_required', $result['checks']['audit_freshness']['status']);
        $this->assertStringContainsString('missing a certified commit hash', $result['checks']['audit_freshness']['message']);
    }
}
