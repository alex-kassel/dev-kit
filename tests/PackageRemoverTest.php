<?php

declare(strict_types=1);

namespace AlexKassel\DevKit\Tests;

use AlexKassel\DevKit\PackageRemover;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PackageRemoverTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/dev-kit-remover-test-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0777, true);
        mkdir($this->root.'/packages/mine/example', 0777, true);
        file_put_contents($this->root.'/packages/mine/example/composer.json', json_encode([
            'name' => 'mine/example',
            'description' => 'Test package',
        ]));
        file_put_contents($this->root.'/composer.json', json_encode([
            'name' => 'host/app',
            'repositories' => [
                ['type' => 'path', 'url' => 'packages/*/*'],
            ],
            'require' => [
                'mine/example' => '@dev',
            ],
        ]));
    }

    protected function tearDown(): void
    {
        if (file_exists($this->root.'/packages/mine/example/composer.json')) {
            unlink($this->root.'/packages/mine/example/composer.json');
        }
        if (is_dir($this->root.'/packages/mine/example')) {
            rmdir($this->root.'/packages/mine/example');
        }
        if (is_dir($this->root.'/packages/mine')) {
            rmdir($this->root.'/packages/mine');
        }
        if (is_dir($this->root.'/packages')) {
            rmdir($this->root.'/packages');
        }
        if (file_exists($this->root.'/composer.json')) {
            unlink($this->root.'/composer.json');
        }
        if (file_exists($this->root.'/.composer-manifest.lock')) {
            unlink($this->root.'/.composer-manifest.lock');
        }
        if (is_dir($this->root)) {
            rmdir($this->root);
        }
    }

    public function test_removes_non_git_package_cleanly(): void
    {
        $remover = new PackageRemover;
        $result = $remover->remove($this->root, 'mine/example');

        $this->assertSame('removed', $result['status']);
        $this->assertTrue($result['deleted']);
        $this->assertDirectoryDoesNotExist($this->root.'/packages/mine/example');

        $rootComposer = json_decode(file_get_contents($this->root.'/composer.json'), true);
        $this->assertArrayNotHasKey('mine/example', $rootComposer['require'] ?? []);
    }

    public function test_unlink_only_preserves_directory_on_disk(): void
    {
        $remover = new PackageRemover;
        $result = $remover->remove($this->root, 'mine/example', unlinkOnly: true);

        $this->assertSame('removed', $result['status']);
        $this->assertFalse($result['deleted']);
        $this->assertDirectoryExists($this->root.'/packages/mine/example');
    }

    public function test_throws_when_package_does_not_exist(): void
    {
        $remover = new PackageRemover;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Package directory not found');
        $remover->remove($this->root, 'mine/non-existent');
    }

    public function test_remover_preserves_other_packages_in_root_composer(): void
    {
        // Add a second package to root composer.json and packages dir
        mkdir($this->root.'/packages/mine/second', 0777, true);
        file_put_contents($this->root.'/packages/mine/second/composer.json', json_encode([
            'name' => 'mine/second',
            'description' => 'Second test package',
        ]));

        $rootComposer = json_decode(file_get_contents($this->root.'/composer.json'), true);
        $rootComposer['require']['mine/second'] = '@dev';
        $rootComposer['require']['laravel/framework'] = '^11.0';
        file_put_contents($this->root.'/composer.json', json_encode($rootComposer, JSON_PRETTY_PRINT));

        $remover = new PackageRemover;
        $result = $remover->remove($this->root, 'mine/example');

        $this->assertSame('removed', $result['status']);
        $this->assertDirectoryDoesNotExist($this->root.'/packages/mine/example');
        $this->assertDirectoryExists($this->root.'/packages/mine/second');

        $updatedRootComposer = json_decode(file_get_contents($this->root.'/composer.json'), true);
        $this->assertArrayNotHasKey('mine/example', $updatedRootComposer['require']);
        $this->assertSame('@dev', $updatedRootComposer['require']['mine/second']);
        $this->assertSame('^11.0', $updatedRootComposer['require']['laravel/framework']);

        // Clean up second package
        unlink($this->root.'/packages/mine/second/composer.json');
        rmdir($this->root.'/packages/mine/second');
    }
}
