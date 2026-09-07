<?php

declare(strict_types=1);

namespace AlexKassel\DevKit\Tests;

use AlexKassel\DevKit\OrganizationResolver;
use PHPUnit\Framework\TestCase;

class OrganizationResolverTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = sys_get_temp_dir().DIRECTORY_SEPARATOR.'devkit_org_test_'.bin2hex(random_bytes(6));
        mkdir($this->workspace, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteRecursive($this->workspace);
        parent::tearDown();
    }

    public function test_resolves_target_package_vendor_zero_config(): void
    {
        $resolver = new OrganizationResolver;
        $orgs = $resolver->resolve($this->workspace, 'acme/billing');

        $this->assertSame(['acme'], $orgs);
    }

    public function test_parses_comma_separated_cli_organizations(): void
    {
        $resolver = new OrganizationResolver;
        $orgs = $resolver->resolve(
            $this->workspace,
            'acme/billing',
            'partner-org, second-team, third_team'
        );

        $this->assertSame(['acme', 'partner-org', 'second-team', 'third_team'], $orgs);
    }

    public function test_resolves_from_local_packages_directory(): void
    {
        mkdir($this->workspace.'/packages/internal-tools/pkg1', 0777, true);
        mkdir($this->workspace.'/packages/external-vendor/pkg2', 0777, true);

        $resolver = new OrganizationResolver;
        $orgs = $resolver->resolve($this->workspace);

        $this->assertSame(['external-vendor', 'internal-tools'], $orgs);
    }

    public function test_resolves_host_composer_vendor_if_not_generic(): void
    {
        file_put_contents($this->workspace.'/composer.json', json_encode([
            'name' => 'my-corp/super-app',
        ]));

        $resolver = new OrganizationResolver;
        $orgs = $resolver->resolve($this->workspace);

        $this->assertSame(['my-corp'], $orgs);
    }

    public function test_deduplicates_and_sorts_all_tiers(): void
    {
        mkdir($this->workspace.'/packages/zeta-corp/pkg', 0777, true);
        file_put_contents($this->workspace.'/composer.json', json_encode([
            'name' => 'alpha-inc/app',
        ]));

        $resolver = new OrganizationResolver;
        $orgs = $resolver->resolve(
            $this->workspace,
            'target-vendor/package',
            'cli-vendor, alpha-inc',
            ['config-vendor', 'zeta-corp']
        );

        $this->assertSame([
            'alpha-inc',
            'cli-vendor',
            'config-vendor',
            'target-vendor',
            'zeta-corp',
        ], $orgs);
    }

    public function test_resolves_package_name_with_or_without_vendor(): void
    {
        $resolver = new OrganizationResolver;

        // Already qualified
        $this->assertSame('acme/foo', $resolver->resolvePackageName($this->workspace, 'acme/foo'));

        // Unqualified without existing vendors defaults to alex-kassel
        $this->assertSame('alex-kassel/dev-kit', $resolver->resolvePackageName($this->workspace, 'dev-kit'));

        // Unqualified with CLI org
        $this->assertSame('my-org/core', $resolver->resolvePackageName($this->workspace, 'core', 'my-org'));
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
