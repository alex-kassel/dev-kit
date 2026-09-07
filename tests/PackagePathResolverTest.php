<?php

declare(strict_types=1);

namespace AlexKassel\DevKit\Tests;

use AlexKassel\DevKit\PackagePathResolver;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PackagePathResolverTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/devkit_path_test_'.bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteRecursive($this->tempDir);
        parent::tearDown();
    }

    public function test_resolves_package_in_packages_directory(): void
    {
        $pkgDir = $this->tempDir.'/packages/acme/my-pkg';
        mkdir($pkgDir, 0777, true);

        $resolver = new PackagePathResolver;
        $resolved = $resolver->resolve($this->tempDir, 'acme/my-pkg');

        $this->assertSame(realpath($pkgDir), realpath($resolved));
    }

    public function test_throws_exception_when_package_not_found(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Package directory not found for 'acme/missing'");

        $resolver = new PackagePathResolver;
        $resolver->resolve($this->tempDir, 'acme/missing');
    }

    private function deleteRecursive(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            is_dir($path) ? $this->deleteRecursive($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
