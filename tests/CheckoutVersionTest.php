<?php

declare(strict_types=1);

namespace AlexKassel\DevKit\Tests;

use AlexKassel\DevKit\PackageCloner;
use AlexKassel\DevKit\PackageLocalizer;
use AlexKassel\DevKit\SourceLocator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CheckoutVersionTest extends TestCase
{
    use CreatesWorkspace;

    private string $dependency;

    protected function setUp(): void
    {
        $this->createWorkspace();
        $this->dependency = $this->workspace.'/packages/mine/b';
        mkdir($this->dependency, 0777, true);
        file_put_contents($this->dependency.'/composer.json', '{"name":"mine/b"}');
        $this->initializeRepository($this->dependency);
        mkdir($this->workspace.'/packages/mine/a', 0777, true);
        file_put_contents($this->workspace.'/packages/mine/a/composer.json', '{"name":"mine/a","require":{"mine/b":"^1.0@dev"}}');
        $this->initializeRepository($this->workspace.'/packages/mine/a');
    }

    protected function tearDown(): void
    {
        $this->removeWorkspace();
    }

    public function test_tag_at_head_satisfies_constraint(): void
    {
        $this->git($this->dependency, 'tag', 'v1.2.0');
        $this->assertSame('localized', $this->localize()['status']);
    }

    public function test_historical_tag_cannot_certify_current_checkout(): void
    {
        $this->git($this->dependency, 'tag', 'v1.2.0');
        file_put_contents($this->dependency.'/breaking.php', '<?php function changed(): int { return 2; }');
        $this->git($this->dependency, 'add', '.');
        $this->git($this->dependency, 'commit', '-m', 'test: changed API');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('current checkout versions');
        $this->localize();
    }

    public function test_dirty_checkout_cannot_borrow_head_tag(): void
    {
        $this->git($this->dependency, 'tag', 'v1.2.0');
        file_put_contents($this->dependency.'/uncommitted.php', '<?php return 42;');
        $this->expectException(RuntimeException::class);
        $this->localize();
    }

    public function test_no_version_is_not_accepted_as_a_numeric_release(): void
    {
        $this->expectException(RuntimeException::class);
        $this->localize();
    }

    public function test_matching_branch_alias_is_supported(): void
    {
        file_put_contents($this->dependency.'/composer.json', '{"name":"mine/b","extra":{"branch-alias":{"dev-main":"1.2.x-dev"}}}');
        $this->assertSame('localized', $this->localize()['status']);
    }

    public function test_dev_stability_flag_does_not_bypass_constraint(): void
    {
        file_put_contents($this->dependency.'/composer.json', '{"name":"mine/b","extra":{"branch-alias":{"dev-main":"2.0.x-dev"}}}');
        $this->expectException(RuntimeException::class);
        $this->localize();
    }

    public function test_numeric_branch_alias_uses_composer_source_spelling(): void
    {
        $this->git($this->dependency, 'branch', '-m', '1.x');
        file_put_contents($this->dependency.'/composer.json', '{"name":"mine/b","extra":{"branch-alias":{"1.x-dev":"1.2.x-dev"}}}');
        file_put_contents($this->workspace.'/packages/mine/a/composer.json', '{"name":"mine/a","require":{"mine/b":"~1.2.0@dev"}}');
        $this->assertSame('localized', $this->localize()['status']);
    }

    public function test_numeric_branch_alias_cannot_switch_major_version(): void
    {
        $this->git($this->dependency, 'branch', '-m', '1.x');
        file_put_contents($this->dependency.'/composer.json', '{"name":"mine/b","extra":{"branch-alias":{"1.x-dev":"2.0.x-dev"}}}');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must stay within the source version line');
        $this->localize();
    }

    private function localize(): array
    {
        $sources = new SourceLocator;

        return (new PackageLocalizer(new PackageCloner($sources), $sources))->localize(
            $this->workspace, 'mine/a', sources: ['mine/a' => 'https://example.invalid/a.git', 'mine/b' => 'https://example.invalid/b.git'], organizations: ['mine']
        );
    }
}
