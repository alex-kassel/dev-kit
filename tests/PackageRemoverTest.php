<?php

declare(strict_types=1);

namespace AlexKassel\DevKit\Tests;

use AlexKassel\DevKit\PackageRemover;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

class PackageRemoverTest extends TestCase
{
    use CreatesWorkspace;

    private string $package;

    protected function setUp(): void
    {
        $this->createWorkspace();
        $this->package = $this->workspace.'/packages/mine/example';
        mkdir($this->package, 0777, true);
        file_put_contents($this->package.'/composer.json', '{"name":"mine/example"}');
        file_put_contents($this->workspace.'/composer.json', '{"require":{"mine/example":"@dev","mine/other":"^1.0"}}');
    }

    protected function tearDown(): void
    {
        $this->removeWorkspace();
    }

    public function test_forced_non_git_removal_preserves_other_requirements(): void
    {
        $result = (new PackageRemover)->remove($this->workspace, 'mine/example', force: true);
        $this->assertTrue($result['deleted']);
        $this->assertDirectoryDoesNotExist($this->package);
        $manifest = json_decode(file_get_contents($this->workspace.'/composer.json'), true);
        $this->assertSame(['mine/other' => '^1.0'], $manifest['require']);
    }

    public function test_unlink_preserves_checkout_without_git(): void
    {
        $result = (new PackageRemover)->remove($this->workspace, 'mine/example', unlinkOnly: true);
        $this->assertTrue($result['unlinked']);
        $this->assertFalse($result['deleted']);
        $this->assertDirectoryExists($this->package);
    }

    public function test_traversal_is_rejected_even_with_force(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid Composer package name');
        (new PackageRemover)->remove($this->workspace, '../packages', force: true);
    }

    public function test_non_git_checkout_is_preserved_without_force(): void
    {
        $this->assertRemovalBlocked('Cannot establish safe removal');
    }

    public function test_corrupt_git_metadata_is_not_interpreted_as_clean(): void
    {
        mkdir($this->package.'/.git');
        $this->assertRemovalBlocked('Cannot establish safe removal');
    }

    public function test_dirty_checkout_is_preserved(): void
    {
        $this->initializeRepository($this->package);
        file_put_contents($this->package.'/work.txt', 'uncommitted work');
        $this->assertRemovalBlocked('uncommitted');
    }

    public function test_missing_upstream_is_not_interpreted_as_pushed(): void
    {
        $this->initializeRepository($this->package);
        $this->assertRemovalBlocked('Cannot establish safe removal');
    }

    public function test_clean_pushed_checkout_can_be_removed(): void
    {
        $this->createPushedCheckout();
        $result = (new PackageRemover)->remove($this->workspace, 'mine/example');
        $this->assertTrue($result['deleted']);
        $this->assertDirectoryDoesNotExist($this->package);
    }

    public function test_unpushed_commit_is_preserved(): void
    {
        $this->createPushedCheckout();
        file_put_contents($this->package.'/work.txt', 'valuable work');
        $this->git($this->package, 'add', '.');
        $this->git($this->package, 'commit', '-m', 'test: unpublished work');
        $this->assertRemovalBlocked('unpushed commits');
    }

    public function test_local_branch_work_is_preserved(): void
    {
        $this->createPushedCheckout();
        $this->git($this->package, 'checkout', '-b', 'feature');
        file_put_contents($this->package.'/work.txt', 'valuable work');
        $this->git($this->package, 'add', '.');
        $this->git($this->package, 'commit', '-m', 'test: local branch work');
        $this->git($this->package, 'checkout', 'main');
        $this->assertRemovalBlocked('local commits');
    }

    public function test_invalid_manifest_fails_before_deletion_even_with_force(): void
    {
        file_put_contents($this->workspace.'/composer.json', '{invalid');
        $this->assertRemovalBlocked('Invalid root composer.json', true);
    }

    public function test_linked_package_is_rejected(): void
    {
        $alias = $this->workspace.'/packages/mine/alias';
        if (! @symlink($this->package, $alias)) {
            $this->markTestSkipped('Directory symlink creation is unavailable.');
        }
        try {
            (new PackageRemover)->remove($this->workspace, 'mine/alias', force: true);
            $this->fail('Linked package must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('linked package path', $exception->getMessage());
            $this->assertFileExists($this->package.'/composer.json');
        }
    }

    public function test_windows_junction_cannot_redirect_removal(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('NTFS junctions are Windows-specific.');
        }
        $alias = $this->workspace.'/packages/mine/junction';
        (new Process(['cmd.exe', '/c', 'mklink', '/J', str_replace('/', '\\', $alias), str_replace('/', '\\', $this->package)]))->mustRun();
        try {
            (new PackageRemover)->remove($this->workspace, 'mine/junction', force: true);
            $this->fail('Junction must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('linked package path', $exception->getMessage());
            $this->assertFileExists($this->package.'/composer.json');
        } finally {
            rmdir($alias);
        }
    }

    private function createPushedCheckout(): void
    {
        $this->initializeRepository($this->package);
        $this->git($this->workspace, 'init', '--bare', $this->workspace.'/remote.git');
        $this->git($this->package, 'remote', 'add', 'origin', $this->workspace.'/remote.git');
        $this->git($this->package, 'push', '-u', 'origin', 'main');
    }

    public function test_removal_does_not_follow_a_nested_windows_junction(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('NTFS junctions are Windows-specific.');
        }
        $outside = $this->workspace.'/retained';
        mkdir($outside);
        file_put_contents($outside.'/valuable.txt', 'keep');
        $link = $this->package.'/linked';
        (new Process(['cmd.exe', '/c', 'mklink', '/J', str_replace('/', '\\', $link), str_replace('/', '\\', $outside)]))->mustRun();
        $missing = $this->workspace.'/missing-target';
        mkdir($missing);
        (new Process(['cmd.exe', '/c', 'mklink', '/J', str_replace('/', '\\', $this->package.'/broken'), str_replace('/', '\\', $missing)]))->mustRun();
        rmdir($missing);
        (new PackageRemover)->remove($this->workspace, 'mine/example', force: true);
        $this->assertSame('keep', file_get_contents($outside.'/valuable.txt'));
        $this->assertDirectoryDoesNotExist($this->package);
    }

    private function assertRemovalBlocked(string $message, bool $force = false): void
    {
        $original = file_get_contents($this->workspace.'/composer.json');
        try {
            (new PackageRemover)->remove($this->workspace, 'mine/example', force: $force);
            $this->fail('Unsafe removal was permitted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
            $this->assertDirectoryExists($this->package);
            $this->assertSame($original, file_get_contents($this->workspace.'/composer.json'));
        }
    }
}
