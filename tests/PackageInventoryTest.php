<?php

namespace AlexKassel\DevKit\Tests;

use AlexKassel\DevKit\PackageInventory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PackageInventoryTest extends TestCase
{
    private string $root;

    private array $directories = [];

    private array $files = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/dev-kit-inventory-'.bin2hex(random_bytes(8));
        $this->directory($this->root);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        foreach (array_reverse($this->directories) as $directory) {
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function test_empty_workspace_has_no_packages(): void
    {
        $this->assertSame([], (new PackageInventory)->inspect($this->root, ['mine'], []));
    }

    public function test_it_distinguishes_local_linked_remote_and_uninstalled_packages(): void
    {
        $linked = $this->package('mine/a', '{"name":"mine/a"}');
        $this->package('mine/b', '{"name":"mine/b"}');
        $this->package('other/c', '{"name":"other/c"}');
        $this->directory($this->root.'/remote');
        $packages = (new PackageInventory)->inspect($this->root, ['mine'], [
            'mine/a' => ['path' => $linked, 'version' => 'v1.0.0'],
            'mine/b' => ['path' => $this->root.'/remote', 'version' => 'v2.0.0'],
        ]);
        $this->assertSame(['mine/a', 'mine/b', 'other/c'], array_column($packages, 'name'));
        $this->assertSame([true, true, false], array_column($packages, 'owned'));
        $this->assertSame([true, true, false], array_column($packages, 'installed'));
        $this->assertSame([true, false, false], array_column($packages, 'linked'));
        $this->assertSame(['v1.0.0', 'v2.0.0', null], array_column($packages, 'installed_version'));
        $this->assertSame('packages/mine/a', $packages[0]['path']);
    }

    public function test_ownership_uses_manifest_vendor_and_changes_with_configuration(): void
    {
        $this->package('folder/example', '{"name":"mine/example"}');
        $inventory = new PackageInventory;
        $this->assertTrue($inventory->inspect($this->root, ['mine'], [])[0]['owned']);
        $this->assertFalse($inventory->inspect($this->root, ['folder'], [])[0]['owned']);
    }

    public function test_duplicate_composer_names_are_reported(): void
    {
        $this->package('mine/a', '{"name":"mine/a"}');
        $this->package('mine/copy', '{"name":"mine/a"}');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Duplicate package mine/a');
        (new PackageInventory)->inspect($this->root, [], []);
    }

    public function test_invalid_manifest_is_not_silently_skipped(): void
    {
        $this->package('mine/a', '{broken');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid JSON in packages/mine/a/composer.json');
        (new PackageInventory)->inspect($this->root, [], []);
    }

    public function test_missing_manifest_is_reported(): void
    {
        $path = $this->package('mine/a', '{}');
        unlink($path.'/composer.json');
        $this->files = [];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot read packages/mine/a/composer.json');
        (new PackageInventory)->inspect($this->root, [], []);
    }

    public function test_invalid_organization_configuration_is_reported(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be a list');
        (new PackageInventory)->inspect($this->root, 'mine', []);
    }

    private function package(string $path, string $manifest): string
    {
        $this->directory($this->root.'/packages');
        $this->directory($this->root.'/packages/'.dirname($path));
        $directory = $this->root.'/packages/'.$path;
        $this->directory($directory);
        file_put_contents($directory.'/composer.json', $manifest);
        $this->files[] = $directory.'/composer.json';

        return $directory;
    }

    private function directory(string $path): void
    {
        if (! is_dir($path)) {
            mkdir($path);
            $this->directories[] = $path;
        }
    }
}
