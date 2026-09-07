<?php

declare(strict_types=1);

namespace AlexKassel\DevKit\Tests;

use AlexKassel\DevKit\PackageCloner;
use AlexKassel\DevKit\SourceLocator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Process\Process;

class PackageClonerTest extends TestCase
{
    private string $root;

    private string $source;

    private string $host;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/dev-kit-clone-test-'.bin2hex(random_bytes(8));
        $this->source = $this->root.'/unrelated-repository-name';
        $this->host = $this->root.'/host';
        mkdir($this->source, 0777, true);
        mkdir($this->host);
        file_put_contents($this->host.'/composer.json', '{"name":"test/host"}');
        file_put_contents($this->source.'/composer.json', '{"name":"mine/example","require":{"php":"^8.3","mine/dependency":"^1.0"},"require-dev":{"phpunit/phpunit":"^12.5"}}');
        $this->git(['init', '-b', 'main']);
        $this->commit();
    }

    protected function tearDown(): void
    {
        $resolved = realpath($this->root);
        $parent = realpath(sys_get_temp_dir());
        if ($resolved === false || dirname($resolved) !== $parent || ! str_starts_with(basename($resolved), 'dev-kit-clone-test-')) {
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

    public function test_clone_uses_explicit_mapping_and_returns_dependencies_without_installing(): void
    {
        $before = file_get_contents($this->host.'/composer.json');
        $result = $this->clone();
        $this->assertSame('cloned', $result['status']);
        $this->assertSame('main', $result['branch']);
        $this->assertSame('^1.0', $result['require']->{'mine/dependency'});
        $this->assertSame('^12.5', $result['require_dev']->{'phpunit/phpunit'});
        $this->assertFalse($result['composer_updated']);
        $this->assertDirectoryExists($this->host.'/packages/mine/example/.git');
        $this->assertDirectoryDoesNotExist($this->host.'/packages/mine/dependency');
        $this->assertDirectoryDoesNotExist($this->host.'/vendor');
        $this->assertSame($before, file_get_contents($this->host.'/composer.json'));
        $this->assertSame(trim($this->git(['rev-parse', 'HEAD'])), $result['commit']);
    }

    public function test_existing_checkout_and_dirty_files_are_preserved(): void
    {
        $this->clone();
        $path = $this->host.'/packages/mine/example/composer.json';
        file_put_contents($path, 'my uncommitted changes');
        try {
            $this->clone();
            $this->fail('Expected existing package error');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('already exists', $exception->getMessage());
        }
        $this->assertSame('my uncommitted changes', file_get_contents($path));
    }

    public function test_missing_branch_does_not_fall_back_to_main(): void
    {
        try {
            $this->clone('missing-branch');
            $this->fail('Expected Git failure');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Git failed', $exception->getMessage());
        }
        $this->assertDirectoryDoesNotExist($this->host.'/packages/mine/example');
    }

    public function test_wrong_manifest_name_is_not_promoted_to_packages(): void
    {
        file_put_contents($this->source.'/composer.json', '{"name":"someone/else"}');
        $this->commit();
        try {
            $this->clone();
            $this->fail('Expected manifest mismatch');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('name does not match', $exception->getMessage());
            $this->assertStringContainsString('retained for inspection', $exception->getMessage());
        }
        $this->assertDirectoryDoesNotExist($this->host.'/packages/mine/example');
    }

    public function test_unknown_package_source_does_not_guess_github_url(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No valid source configured');
        (new SourceLocator)->resolve('mine/unknown', []);
    }

    public function test_package_path_traversal_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid Composer package name');
        (new SourceLocator)->resolve('../outside', ['../outside' => $this->source]);
    }

    public function test_default_branch_is_autodetected_when_branch_omitted(): void
    {
        $result = (new PackageCloner(new SourceLocator))->clonePackage($this->host, 'mine/example', null, ['mine/example' => $this->source]);
        $this->assertSame('main', $result['branch']);
    }

    public function test_existing_file_is_not_treated_as_a_checkout(): void
    {
        mkdir($this->host.'/packages/mine', 0777, true);
        file_put_contents($this->host.'/packages/mine/example', 'keep');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not a directory');
        (new PackageCloner(new SourceLocator))->inspectCheckout($this->host, 'mine/example');
    }

    private function clone(string $branch = 'main'): array
    {
        return (new PackageCloner(new SourceLocator))->clonePackage($this->host, 'mine/example', $branch, ['mine/example' => $this->source]);
    }

    private function commit(): void
    {
        $this->git(['add', '.']);
        $this->git(['-c', 'user.name=Dev-kit test', '-c', 'user.email=test@example.invalid', '-c', 'commit.gpgsign=false', 'commit', '-m', 'Fixture']);
    }

    private function git(array $arguments): string
    {
        $process = new Process(['git', ...$arguments], $this->source);
        $process->mustRun();

        return $process->getOutput();
    }
}
