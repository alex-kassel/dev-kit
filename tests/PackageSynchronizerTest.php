<?php

namespace AlexKassel\DevKit\Tests;

use AlexKassel\DevKit\PackageSynchronizer;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

class PackageSynchronizerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/dev-kit-sync-test-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0777, true);
        file_put_contents($this->root.'/composer.json', json_encode([
            'name' => 'test/host',
            'require' => [
                'php' => '^8.2',
                'laravel/framework' => '^12.0',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    protected function tearDown(): void
    {
        $resolved = realpath($this->root);
        $parent = realpath(sys_get_temp_dir());
        if ($resolved === false || dirname($resolved) !== $parent || ! str_starts_with(basename($resolved), 'dev-kit-sync-test-')) {
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

    private function createPackage(string $vendor, string $package, ?string $declaredName = null): void
    {
        $pkgDir = $this->root.'/packages/'.$vendor.'/'.$package;
        mkdir($pkgDir, 0777, true);
        file_put_contents($pkgDir.'/composer.json', json_encode([
            'name' => $declaredName ?? "{$vendor}/{$package}",
            'type' => 'library',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function test_sync_discovers_packages_and_updates_root_composer(): void
    {
        $this->createPackage('alex-kassel', 'pkg-b');
        $this->createPackage('alex-kassel', 'pkg-a');

        $synchronizer = new PackageSynchronizer;
        $result = $synchronizer->sync($this->root);

        $this->assertSame('success', $result['status']);
        $this->assertFalse($result['dry_run']);
        $this->assertSame(2, $result['total_discovered_on_disk']);
        $this->assertSame(2, $result['registered_count']);
        $this->assertSame(['alex-kassel/pkg-a', 'alex-kassel/pkg-b'], $result['added']);
        $this->assertSame([], $result['removed']);

        $composerData = json_decode((string) file_get_contents($this->root.'/composer.json'), true);
        $this->assertIsArray($composerData);
        $this->assertSame('^8.2', $composerData['require']['php']);
        $this->assertSame('@dev', $composerData['require']['alex-kassel/pkg-a']);
        $this->assertSame('@dev', $composerData['require']['alex-kassel/pkg-b']);

        // Check path repository added
        $this->assertNotEmpty($composerData['repositories']);
        $this->assertSame('packages/*/*', $composerData['repositories'][0]['url']);
    }

    public function test_sync_filter_option(): void
    {
        $this->createPackage('alex-kassel', 'scraper-core');
        $this->createPackage('alex-kassel', 'notifier');

        $synchronizer = new PackageSynchronizer;
        $result = $synchronizer->sync($this->root, false, '*scraper*');

        $this->assertSame(2, $result['total_discovered_on_disk']);
        $this->assertSame(1, $result['registered_count']);
        $this->assertSame(['alex-kassel/scraper-core'], $result['added']);

        $composerData = json_decode((string) file_get_contents($this->root.'/composer.json'), true);
        $this->assertIsArray($composerData);
        $this->assertArrayHasKey('alex-kassel/scraper-core', $composerData['require']);
        $this->assertArrayNotHasKey('alex-kassel/notifier', $composerData['require']);
    }

    public function test_sync_clean_mode_removes_all_dev_packages(): void
    {
        $this->createPackage('alex-kassel', 'pkg-a');
        $synchronizer = new PackageSynchronizer;
        $synchronizer->sync($this->root);

        // Run with clean mode
        $cleanResult = $synchronizer->sync($this->root, clean: true);

        $this->assertSame(0, $cleanResult['registered_count']);
        $this->assertSame(['alex-kassel/pkg-a'], $cleanResult['removed']);

        $composerData = json_decode((string) file_get_contents($this->root.'/composer.json'), true);
        $this->assertIsArray($composerData);
        $this->assertArrayNotHasKey('alex-kassel/pkg-a', $composerData['require']);
        $this->assertSame('^8.2', $composerData['require']['php']);
    }

    public function test_sync_dry_run_does_not_modify_file(): void
    {
        $this->createPackage('alex-kassel', 'pkg-a');
        $before = file_get_contents($this->root.'/composer.json');

        $synchronizer = new PackageSynchronizer;
        $result = $synchronizer->sync($this->root, dryRun: true);

        $this->assertTrue($result['dry_run']);
        $this->assertSame(['alex-kassel/pkg-a'], $result['added']);
        $this->assertSame($before, file_get_contents($this->root.'/composer.json'));
    }

    public function test_sync_throws_if_root_not_found(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Host directory does not exist.');

        (new PackageSynchronizer)->sync($this->root.'/non-existent');
    }
}
