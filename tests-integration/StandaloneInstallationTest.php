<?php

declare(strict_types=1);

namespace AlexKassel\DevKit\Tests;

use AlexKassel\DevKit\IsolatedPackageVerifier;
use PHPUnit\Framework\TestCase;

class StandaloneInstallationTest extends TestCase
{
    use CreatesWorkspace;

    protected function setUp(): void
    {
        $this->createWorkspace();
    }

    protected function tearDown(): void
    {
        $this->removeWorkspace();
    }

    public function test_declared_dependencies_install_and_run_without_host(): void
    {
        $result = $this->verifyFixture(true);
        $this->assertSame('passed', $result['status'], $result['output']);
        $this->assertStringContainsString('OK (1 test, 1 assertion)', $result['output']);
    }

    public function test_host_dependency_cannot_leak_into_grouped_import(): void
    {
        $result = $this->verifyFixture(false);
        $this->assertSame('failed', $result['status'], $result['output']);
        $this->assertStringContainsString('Illuminate\\Support\\Collection', $result['output']);
    }

    private function verifyFixture(bool $declareDependency): array
    {
        $package = $this->workspace.'/package';
        mkdir($package.'/tests', 0777, true);
        $manifest = ['name' => 'dev-kit-tests/standalone', 'license' => 'MIT', 'require-dev' => ['phpunit/phpunit' => '^11.0 || ^12.0']];
        if ($declareDependency) {
            $manifest['require'] = ['illuminate/support' => '^11.0 || ^12.0 || ^13.0'];
        }
        file_put_contents($package.'/composer.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        file_put_contents($package.'/phpunit.xml', '<phpunit bootstrap="vendor/autoload.php"><testsuites><testsuite name="consumer"><directory>tests</directory></testsuite></testsuites></phpunit>');
        file_put_contents($package.'/tests/ConsumerTest.php', <<<'PHP'
<?php
use Illuminate\Support\{Collection, Str};
use PHPUnit\Framework\TestCase;
final class ConsumerTest extends TestCase
{
    public function test_collection_and_string_contract(): void
    {
        self::assertSame(['FIRST', 'SECOND'], (new Collection(['first', 'second']))->map(fn (string $value): string => Str::upper($value))->all());
    }
}
PHP);
        $this->initializeRepository($package);

        return (new IsolatedPackageVerifier)->verify($package);
    }
}
