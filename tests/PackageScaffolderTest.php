<?php

declare(strict_types=1);

namespace AlexKassel\DevKit\Tests;

use AlexKassel\DevKit\PackageScaffolder;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

class PackageScaffolderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/dev-kit-scaffold-test-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
                if ($entry->isDir() && ! $entry->isLink()) {
                    @rmdir($entry->getPathname());
                } else {
                    @chmod($entry->getPathname(), 0666);
                    @unlink($entry->getPathname());
                }
            }
            @rmdir($this->root);
        }
    }

    public function test_scaffold_rejects_invalid_archetype(): void
    {
        $scaffolder = new PackageScaffolder;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Invalid archetype 'invalid'");
        $scaffolder->scaffold($this->root, 'mine/pkg', 'invalid');
    }

    public function test_scaffold_rejects_invalid_package_name(): void
    {
        $scaffolder = new PackageScaffolder;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Must be in \'vendor/package-name\' format');
        $scaffolder->scaffold($this->root, 'single-name');
    }

    public function test_scaffold_rejects_path_traversal_in_package_name(): void
    {
        $scaffolder = new PackageScaffolder;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Must be in \'vendor/package-name\' format');
        $scaffolder->scaffold($this->root, '../outside/pkg');
    }

    public function test_dry_run_does_not_write_files_to_disk(): void
    {
        $scaffolder = new PackageScaffolder;
        $result = $scaffolder->scaffold($this->root, 'mine/example-tool', 'library', false, false, true);

        $this->assertSame('created', $result['status']);
        $this->assertTrue($result['dry_run']);
        $this->assertSame(12, $result['files_count']);
        $this->assertDirectoryDoesNotExist($this->root.'/packages');
    }

    public function test_scaffold_creates_all_12_standard_files_and_directories(): void
    {
        $scaffolder = new PackageScaffolder;
        $result = $scaffolder->scaffold($this->root, 'alex-kassel/billing-core', 'engine', false, false, false);

        $this->assertSame('created', $result['status']);
        $this->assertFalse($result['dry_run']);
        $this->assertSame(12, $result['files_count']);

        $packageDir = $this->root.'/packages/alex-kassel/billing-core';
        $this->assertFileExists($packageDir.'/composer.json');
        $this->assertFileExists($packageDir.'/src/BillingCoreServiceProvider.php');
        $this->assertFileExists($packageDir.'/tests/bootstrap.php');
        $this->assertFileExists($packageDir.'/tests/TestCase.php');
        $this->assertFileExists($packageDir.'/tests/Unit/ServiceProviderTest.php');
        $this->assertFileExists($packageDir.'/phpunit.xml');
        $this->assertFileExists($packageDir.'/phpstan.neon');
        $this->assertFileExists($packageDir.'/.gitignore');
        $this->assertFileExists($packageDir.'/.gitattributes');
        $this->assertFileExists($packageDir.'/LICENSE');
        $this->assertFileExists($packageDir.'/CHANGELOG.md');
        $this->assertFileExists($packageDir.'/README.md');

        $composer = json_decode((string) file_get_contents($packageDir.'/composer.json'), true);
        $this->assertSame('alex-kassel/billing-core', $composer['name']);
        $this->assertContains('engine', $composer['keywords']);
        $this->assertArrayHasKey('AlexKassel\\BillingCore\\', $composer['autoload']['psr-4']);
    }

    public function test_scaffold_aborts_if_directory_already_exists_and_not_empty(): void
    {
        $dir = $this->root.'/packages/mine/existing';
        mkdir($dir, 0777, true);
        file_put_contents($dir.'/file.txt', 'data');

        $scaffolder = new PackageScaffolder;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already exists and is not empty');
        $scaffolder->scaffold($this->root, 'mine/existing');
    }
}
