<?php

declare(strict_types=1);

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
        $this->deleteDirectory($this->root);
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
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

    public function test_install_registers_scripts_and_installs_agent_files(): void
    {
        $installer = new WorkspaceInstaller;
        $result = $installer->install($this->root);

        $this->assertSame('installed', $result['status']);
        $this->assertContains('AGENTS.md', $result['files']);
        $this->assertContains('.agents/skills/package-verification/SKILL.md', $result['files']);

        $this->assertFileExists($this->root.'/AGENTS.md');
        $this->assertFileExists($this->root.'/.agents/skills/package-verification/SKILL.md');
        $this->assertFileExists($this->root.'/.agents/skills/package-scaffolding/SKILL.md');

        $manifest = json_decode(file_get_contents($this->root.'/composer.json'));
        $this->assertSame('@php artisan pkg:check', $manifest->scripts->{'pkg:check'});
        $this->assertSame('@php artisan pkg:sync', $manifest->scripts->{'pkg:sync'});
        $this->assertSame('@php artisan pkg:install', $manifest->scripts->{'pkg:install'});
        $this->assertSame('phpunit', $manifest->scripts->test);

        // Second run without modifications should be unchanged
        $secondResult = $installer->install($this->root);
        $this->assertSame('unchanged', $secondResult['status']);
    }

    public function test_force_overwrites_agent_files(): void
    {
        $installer = new WorkspaceInstaller;
        $installer->install($this->root);

        file_put_contents($this->root.'/AGENTS.md', '# Modified Agents');

        $noForceResult = $installer->install($this->root, false, false);
        $this->assertSame('unchanged', $noForceResult['status']);
        $this->assertSame('# Modified Agents', file_get_contents($this->root.'/AGENTS.md'));

        $forceResult = $installer->install($this->root, false, true);
        $this->assertSame('installed', $forceResult['status']);
        $this->assertContains('AGENTS.md', $forceResult['files']);
        $this->assertStringContainsString('AGENTS.MD — Repository Guidelines', file_get_contents($this->root.'/AGENTS.md'));
    }

    public function test_auto_replaces_laravel_boost_placeholder_without_force(): void
    {
        $installer = new WorkspaceInstaller;

        // Simulate a default skeleton with Laravel Boost placeholder
        file_put_contents($this->root.'/AGENTS.md', "# Boost Guidelines\n<laravel-boost-guidelines>\nRun boost commands\n");

        $result = $installer->install($this->root, false, false);

        $this->assertSame('installed', $result['status']);
        $this->assertContains('AGENTS.md', $result['files']);
        $this->assertStringContainsString('AGENTS.MD — Repository Guidelines', file_get_contents($this->root.'/AGENTS.md'));
    }
}
