<?php

namespace AlexKassel\DevKit\Tests;

use AlexKassel\DevKit\WorkspaceInstaller;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class WorkspaceInstallerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/dev-kit-test-'.bin2hex(random_bytes(8));
        mkdir($this->root);
        file_put_contents($this->root.'/composer.json', '{"name":"test/host","require":{},"scripts":{"test":"phpunit"}}');
    }

    protected function tearDown(): void
    {
        foreach (['composer.json', '.gitignore', 'packages/keep.txt'] as $path) {
            if (is_file($this->root.'/'.$path)) {
                unlink($this->root.'/'.$path);
            }
        }
        if (is_dir($this->root.'/packages')) {
            rmdir($this->root.'/packages');
        } elseif (is_file($this->root.'/packages')) {
            unlink($this->root.'/packages');
        }
        rmdir($this->root);
    }

    public function test_install_preserves_settings_and_second_run_is_byte_identical(): void
    {
        file_put_contents($this->root.'/.gitignore', "/vendor/\r\n# keep comment");
        $installer = new WorkspaceInstaller;
        $this->assertSame('installed', $installer->install($this->root)['status']);
        $composer = file_get_contents($this->root.'/composer.json');
        $ignore = file_get_contents($this->root.'/.gitignore');
        $manifest = json_decode($composer);
        $this->assertInstanceOf(\stdClass::class, $manifest->require);
        $this->assertSame('phpunit', $manifest->scripts->test);
        $this->assertTrue($manifest->repositories[0]->options->symlink);
        $this->assertTrue($manifest->repositories[0]->canonical);
        $this->assertSame("/vendor/\r\n# keep comment\r\n/packages/*/*\r\n", $ignore);
        $this->assertDirectoryExists($this->root.'/packages');
        $this->assertSame('unchanged', $installer->install($this->root)['status']);
        $this->assertSame($composer, file_get_contents($this->root.'/composer.json'));
        $this->assertSame($ignore, file_get_contents($this->root.'/.gitignore'));
    }

    public function test_dry_run_does_not_write_anything(): void
    {
        $before = file_get_contents($this->root.'/composer.json');
        $this->assertSame('planned', (new WorkspaceInstaller)->install($this->root, true)['status']);
        $this->assertSame($before, file_get_contents($this->root.'/composer.json'));
        $this->assertFileDoesNotExist($this->root.'/.gitignore');
        $this->assertDirectoryDoesNotExist($this->root.'/packages');
    }

    public function test_named_repositories_and_disabled_packagist_are_preserved(): void
    {
        file_put_contents($this->root.'/composer.json', '{"repositories":{"private":{"type":"composer","url":"https://example.invalid"},"packagist.org":false}}');
        (new WorkspaceInstaller)->install($this->root);
        $repositories = json_decode(file_get_contents($this->root.'/composer.json'))->repositories;
        $this->assertSame('dev-kit-workspace', array_key_first((array) $repositories));
        $this->assertSame('https://example.invalid', $repositories->private->url);
        $this->assertFalse($repositories->{'packagist.org'});
    }

    public function test_existing_repository_moves_first_without_losing_options_or_package_files(): void
    {
        file_put_contents($this->root.'/composer.json', '{"repositories":[{"packagist.org":false},{"type":"path","url":"./packages/*/*","options":{"symlink":true,"reference":"config"}}]}');
        mkdir($this->root.'/packages');
        file_put_contents($this->root.'/packages/keep.txt', 'local work');
        (new WorkspaceInstaller)->install($this->root);
        $repositories = json_decode(file_get_contents($this->root.'/composer.json'))->repositories;
        $this->assertCount(2, $repositories);
        $this->assertSame('config', $repositories[0]->options->reference);
        $this->assertFalse($repositories[1]->{'packagist.org'});
        $this->assertSame('local work', file_get_contents($this->root.'/packages/keep.txt'));
    }

    public function test_conflicting_repository_fails_before_any_write(): void
    {
        $original = '{"repositories":[{"type":"path","url":"packages/*/*","canonical":false}]}';
        file_put_contents($this->root.'/composer.json', $original);
        try {
            (new WorkspaceInstaller)->install($this->root);
            $this->fail('Expected repository conflict');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('canonical=true', $exception->getMessage());
        }
        $this->assertSame($original, file_get_contents($this->root.'/composer.json'));
        $this->assertFileDoesNotExist($this->root.'/.gitignore');
        $this->assertDirectoryDoesNotExist($this->root.'/packages');
    }

    public function test_invalid_json_fails_without_changes(): void
    {
        file_put_contents($this->root.'/composer.json', '{broken');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid composer.json');
        (new WorkspaceInstaller)->install($this->root);
    }

    public function test_later_unignore_rule_cannot_undo_package_protection(): void
    {
        file_put_contents($this->root.'/.gitignore', "/packages/*/*\n!/packages/test/example\n");
        (new WorkspaceInstaller)->install($this->root);
        $this->assertStringEndsWith("!/packages/test/example\n/packages/*/*\n", file_get_contents($this->root.'/.gitignore'));
        $this->assertSame('unchanged', (new WorkspaceInstaller)->install($this->root)['status']);
    }

    public function test_mirroring_configuration_is_not_silently_overwritten(): void
    {
        $original = '{"repositories":[{"type":"path","url":"packages/*/*","options":{"symlink":false}}]}';
        file_put_contents($this->root.'/composer.json', $original);
        try {
            (new WorkspaceInstaller)->install($this->root);
            $this->fail('Expected symlink conflict');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('options.symlink=true', $exception->getMessage());
        }
        $this->assertSame($original, file_get_contents($this->root.'/composer.json'));
        $this->assertFileDoesNotExist($this->root.'/.gitignore');
    }

    public function test_packages_file_collision_fails_before_writing_composer(): void
    {
        $original = file_get_contents($this->root.'/composer.json');
        file_put_contents($this->root.'/packages', 'keep');
        try {
            (new WorkspaceInstaller)->install($this->root);
            $this->fail('Expected directory collision');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('not a directory', $exception->getMessage());
        }
        $this->assertSame($original, file_get_contents($this->root.'/composer.json'));
        $this->assertSame('keep', file_get_contents($this->root.'/packages'));
    }
}
