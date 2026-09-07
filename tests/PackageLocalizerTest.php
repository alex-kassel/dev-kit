<?php

declare(strict_types=1);

namespace AlexKassel\DevKit\Tests;

use AlexKassel\DevKit\PackageCloner;
use AlexKassel\DevKit\PackageLocalizer;
use AlexKassel\DevKit\SourceLocator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PackageLocalizerTest extends TestCase
{
    private array $calls = [];

    public function test_cycle_terminates_and_each_package_is_cloned_once(): void
    {
        $result = $this->runGraph(['mine/a' => ['mine/b' => 'dev-main'], 'mine/b' => ['mine/a' => 'dev-main']]);
        $this->assertSame(['mine/a', 'mine/b'], $this->calls);
        $this->assertCount(2, $result['packages']);
        $this->assertCount(2, $result['requirements']);
    }

    public function test_diamond_retains_both_constraints_without_repeating_clone(): void
    {
        $result = $this->runGraph([
            'mine/a' => ['mine/b' => 'dev-main', 'mine/c' => 'dev-main'],
            'mine/b' => ['mine/d' => '^1.0 || dev-main'],
            'mine/c' => ['mine/d' => 'dev-main'],
            'mine/d' => [],
        ]);
        $this->assertSame(['mine/a', 'mine/b', 'mine/d', 'mine/c'], $this->calls);
        $this->assertCount(4, $result['requirements']);
        $this->assertSame('^1.0 || dev-main', $result['requirements'][1]['constraint']);
    }

    public function test_conflicting_second_constraint_is_not_hidden_by_visited_set(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires mine/d dev-other but local branch is main');
        $this->runGraph([
            'mine/a' => ['mine/b' => 'dev-main', 'mine/c' => 'dev-main'],
            'mine/b' => ['mine/d' => 'dev-main'],
            'mine/c' => ['mine/d' => 'dev-other'],
            'mine/d' => [],
        ]);
    }

    public function test_external_and_dev_only_dependencies_are_not_cloned(): void
    {
        $result = $this->runGraph(['mine/a' => ['php' => '^8.3', 'external/b' => '^1.0']]);
        $this->assertSame(['mine/a'], $this->calls);
        $this->assertSame([false, false], array_column($result['requirements'], 'localize'));
    }

    public function test_missing_source_stops_without_skipping_owned_dependency(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No valid source configured for mine/missing');
        $this->runGraph(['mine/a' => ['mine/missing' => 'dev-main']]);
    }

    public function test_numeric_constraint_does_not_invent_a_branch(): void
    {
        $result = $this->runGraph(['mine/a' => ['mine/b' => '^1.0'], 'mine/b' => []]);
        $this->assertSame(['mine/a', 'mine/b'], $this->calls);
    }

    public function test_multiple_dev_alternatives_need_explicit_selection(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Ambiguous ref selection');
        $this->runGraph(['mine/a' => ['mine/b' => 'dev-main || dev-other'], 'mine/b' => []]);
    }

    public function test_semver_constraint_satisfied_by_package_declared_version(): void
    {
        $root = sys_get_temp_dir().'/dev-kit-semver-test-'.bin2hex(random_bytes(8));
        mkdir($root.'/packages/mine/b', 0777, true);
        file_put_contents($root.'/packages/mine/b/composer.json', json_encode([
            'name' => 'mine/b',
            'version' => '1.2.0',
        ]));

        $cloner = $this->createMock(PackageCloner::class);
        $cloner->method('clonePackage')->willReturnCallback(function (string $r, string $name, ?string $branch = null): array {
            return [
                'name' => $name,
                'path' => 'packages/'.$name,
                'branch' => $branch ?? 'main',
                'commit' => 'fixture',
                'require' => (object) ($name === 'mine/a' ? ['mine/b' => '^1.0'] : []),
                'require_dev' => (object) [],
            ];
        });
        $cloner->method('inspectCheckout')->willReturnCallback(function (string $r, string $name): array {
            return [
                'name' => $name,
                'path' => 'packages/'.$name,
                'branch' => 'main',
                'commit' => 'fixture',
                'require' => (object) [],
                'require_dev' => (object) [],
            ];
        });

        $sources = ['mine/a' => 'https://example.invalid/a.git', 'mine/b' => 'https://example.invalid/b.git'];
        $localizer = new PackageLocalizer($cloner, new SourceLocator);
        $result = $localizer->localize($root, 'mine/a', 'main', $sources, ['mine']);

        $this->assertSame('localized', $result['status']);
        $this->assertCount(2, $result['packages']);

        // Cleanup
        unlink($root.'/packages/mine/b/composer.json');
        rmdir($root.'/packages/mine/b');
        rmdir($root.'/packages/mine');
        rmdir($root.'/packages');
        rmdir($root);
    }

    public function test_semver_constraint_fails_when_package_version_incompatible(): void
    {
        $root = sys_get_temp_dir().'/dev-kit-semver-incompatible-'.bin2hex(random_bytes(8));
        mkdir($root.'/packages/mine/b', 0777, true);
        file_put_contents($root.'/packages/mine/b/composer.json', json_encode([
            'name' => 'mine/b',
            'version' => '2.0.0',
        ]));

        $cloner = $this->createMock(PackageCloner::class);
        $cloner->method('clonePackage')->willReturnCallback(function (string $r, string $name, ?string $branch = null): array {
            return [
                'name' => $name,
                'path' => 'packages/'.$name,
                'branch' => $branch ?? 'main',
                'commit' => 'fixture',
                'require' => (object) ($name === 'mine/a' ? ['mine/b' => '^1.0'] : []),
                'require_dev' => (object) [],
            ];
        });
        $cloner->method('inspectCheckout')->willReturnCallback(function (string $r, string $name): array {
            return [
                'name' => $name,
                'path' => 'packages/'.$name,
                'branch' => 'main',
                'commit' => 'fixture',
                'require' => (object) [],
                'require_dev' => (object) [],
            ];
        });

        $sources = ['mine/a' => 'https://example.invalid/a.git', 'mine/b' => 'https://example.invalid/b.git'];
        $localizer = new PackageLocalizer($cloner, new SourceLocator);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('mine/a requires mine/b ^1.0, but local package versions (2.0.0, dev-main) do not satisfy the constraint.');
            $localizer->localize($root, 'mine/a', 'main', $sources, ['mine']);
        } finally {
            if (file_exists($root.'/packages/mine/b/composer.json')) {
                unlink($root.'/packages/mine/b/composer.json');
            }
            if (is_dir($root.'/packages/mine/b')) {
                rmdir($root.'/packages/mine/b');
            }
            if (is_dir($root.'/packages/mine')) {
                rmdir($root.'/packages/mine');
            }
            if (is_dir($root.'/packages')) {
                rmdir($root.'/packages');
            }
            if (is_dir($root)) {
                rmdir($root);
            }
        }
    }

    private function runGraph(array $graph): array
    {
        $cloner = $this->createMock(PackageCloner::class);
        $cloner->method('clonePackage')->willReturnCallback(function (string $root, string $name, ?string $branch = null) use ($graph): array {
            $this->calls[] = $name;

            return [
                'name' => $name,
                'path' => 'packages/'.$name,
                'branch' => $branch,
                'commit' => 'fixture',
                'require' => (object) $graph[$name],
                'require_dev' => (object) ['mine/dev-only' => 'dev-main'],
            ];
        });
        $sources = array_fill_keys(array_keys($graph), 'https://example.invalid/test.git');

        return (new PackageLocalizer($cloner, new SourceLocator))->localize(sys_get_temp_dir().'/dev-kit-graph-'.bin2hex(random_bytes(8)), 'mine/a', 'main', $sources, ['mine']);
    }
}
