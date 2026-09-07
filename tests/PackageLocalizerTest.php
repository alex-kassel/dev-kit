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
