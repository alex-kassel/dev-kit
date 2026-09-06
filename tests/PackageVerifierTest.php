<?php

namespace AlexKassel\DevKit\Tests;

use AlexKassel\DevKit\PackageVerifier;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PackageVerifierTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/dev-kit-verify-test-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0777, true);
        mkdir($this->root.'/packages/mine/example', 0777, true);
        file_put_contents($this->root.'/packages/mine/example/composer.json', json_encode([
            'name' => 'mine/example',
            'description' => 'Test package',
            'license' => 'MIT',
        ]));
    }

    protected function tearDown(): void
    {
        $file = $this->root.'/packages/mine/example/composer.json';
        if (file_exists($file)) {
            unlink($file);
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
        if (is_dir($this->root)) {
            rmdir($this->root);
        }
    }

    public function test_verify_fails_for_non_existent_package(): void
    {
        $verifier = new PackageVerifier;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Package directory not found');
        $verifier->verify($this->root, 'mine/non-existent');
    }

    public function test_verify_returns_structured_results_with_composer_check(): void
    {
        $verifier = new PackageVerifier;
        $result = $verifier->verify($this->root, 'mine/example', false, ['composer']);

        $this->assertSame('mine/example', $result['package']);
        $this->assertSame('packages/mine/example', $result['path']);
        $this->assertArrayHasKey('composer', $result['checks']);
        $this->assertArrayHasKey('summary', $result);
    }

    public function test_verify_reports_not_configured_when_configs_missing(): void
    {
        $verifier = new PackageVerifier;
        $result = $verifier->verify($this->root, 'mine/example', false, ['phpstan', 'tests']);

        $this->assertSame('not_configured', $result['checks']['phpstan']['status']);
        $this->assertSame('not_configured', $result['checks']['tests']['status']);
    }

    public function test_verify_all_discovers_workspace_packages(): void
    {
        $verifier = new PackageVerifier;
        $result = $verifier->verifyAll($this->root, false, ['phpstan', 'tests']);

        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['results']);
        $this->assertSame('mine/example', $result['results'][0]['package']);
    }
}
