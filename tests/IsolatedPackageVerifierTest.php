<?php

declare(strict_types=1);

namespace AlexKassel\DevKit\Tests;

use AlexKassel\DevKit\IsolatedPackageVerifier;
use PHPUnit\Framework\TestCase;

class IsolatedPackageVerifierTest extends TestCase
{
    use CreatesWorkspace;

    protected function setUp(): void
    {
        $this->createWorkspace();
        mkdir($this->workspace.'/source');
        mkdir($this->workspace.'/export');
    }

    protected function tearDown(): void
    {
        $this->removeWorkspace();
    }

    public function test_export_includes_current_tests_even_when_export_ignored(): void
    {
        $source = $this->workspace.'/source';
        file_put_contents($source.'/composer.json', '{"name":"mine/example"}');
        file_put_contents($source.'/.gitattributes', '/tests export-ignore');
        file_put_contents($source.'/.gitignore', "vendor/\n.env\n");
        mkdir($source.'/tests');
        file_put_contents($source.'/tests/ExampleTest.php', '<?php return 1;');
        $this->initializeRepository($source);
        file_put_contents($source.'/tests/NewTest.php', '<?php return 2;');
        mkdir($source.'/vendor');
        file_put_contents($source.'/vendor/autoload.php', '<?php return 3;');
        file_put_contents($source.'/.env', 'APP_KEY=fixture');
        (new IsolatedPackageVerifier)->export($source, $this->workspace.'/export');
        $this->assertFileExists($this->workspace.'/export/tests/ExampleTest.php');
        $this->assertFileExists($this->workspace.'/export/tests/NewTest.php');
        $this->assertFileDoesNotExist($this->workspace.'/export/vendor/autoload.php');
        $this->assertFileDoesNotExist($this->workspace.'/export/.env');
        $this->assertDirectoryDoesNotExist($this->workspace.'/export/.git');
    }

    public function test_path_dependencies_fail_before_composer_installation(): void
    {
        $source = $this->workspace.'/source';
        file_put_contents($source.'/composer.json', '{"name":"mine/example","repositories":[{"type":"path","url":"../../host"}]}');
        $this->initializeRepository($source);
        $result = (new IsolatedPackageVerifier)->verify($source);
        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('rejects path repositories', $result['output']);
    }

    public function test_missing_tests_fail_instead_of_passing_an_empty_gate(): void
    {
        $source = $this->workspace.'/source';
        file_put_contents($source.'/composer.json', '{"name":"mine/example"}');
        $this->initializeRepository($source);
        $result = (new IsolatedPackageVerifier)->verify($source);
        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('requires phpunit.xml', $result['output']);
    }
}
