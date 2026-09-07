<?php

declare(strict_types=1);

namespace AlexKassel\DevKit\Tests;

use AlexKassel\DevKit\PhantomDependencyDetector;
use PHPUnit\Framework\TestCase;

class PhantomDependencyDetectorTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = sys_get_temp_dir().DIRECTORY_SEPARATOR.'devkit_phantom_test_'.bin2hex(random_bytes(6));
        mkdir($this->workspace, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteRecursive($this->workspace);
        parent::tearDown();
    }

    public function test_detects_no_phantom_dependencies_when_all_classes_declared(): void
    {
        $this->createInstalledJson([
            [
                'name' => 'symfony/process',
                'require' => [],
                'autoload' => [
                    'psr-4' => [
                        'Symfony\\Component\\Process\\' => 'src/',
                    ],
                ],
            ],
        ]);

        $packageDir = $this->workspace.'/packages/acme/clean-pkg';
        mkdir($packageDir.'/src', 0777, true);
        file_put_contents($packageDir.'/composer.json', json_encode([
            'name' => 'acme/clean-pkg',
            'require' => [
                'symfony/process' => '^7.0',
            ],
            'autoload' => [
                'psr-4' => [
                    'Acme\\CleanPkg\\' => 'src/',
                ],
            ],
        ]));

        file_put_contents($packageDir.'/src/Runner.php', <<<'PHP'
<?php

namespace Acme\CleanPkg;

use Symfony\Component\Process\Process;

class Runner
{
    public function run(): void
    {
        $p = new Process(['ls']);
    }
}
PHP);

        $detector = new PhantomDependencyDetector;
        $result = $detector->detect($this->workspace, $packageDir);

        $this->assertSame('passed', $result['status']);
        $this->assertEmpty($result['violations']);
        $this->assertEmpty($result['undeclared_packages']);
    }

    public function test_detects_phantom_dependency_when_unrequired_package_class_used(): void
    {
        $this->createInstalledJson([
            [
                'name' => 'symfony/process',
                'require' => [],
                'autoload' => [
                    'psr-4' => [
                        'Symfony\\Component\\Process\\' => 'src/',
                    ],
                ],
            ],
            [
                'name' => 'guzzlehttp/guzzle',
                'require' => [],
                'autoload' => [
                    'psr-4' => [
                        'GuzzleHttp\\' => 'src/',
                    ],
                ],
            ],
        ]);

        $packageDir = $this->workspace.'/packages/acme/dirty-pkg';
        mkdir($packageDir.'/src', 0777, true);
        file_put_contents($packageDir.'/composer.json', json_encode([
            'name' => 'acme/dirty-pkg',
            'require' => [
                'symfony/process' => '^7.0',
            ],
            'autoload' => [
                'psr-4' => [
                    'Acme\\DirtyPkg\\' => 'src/',
                ],
            ],
        ]));

        file_put_contents($packageDir.'/src/Fetcher.php', <<<'PHP'
<?php

namespace Acme\DirtyPkg;

use GuzzleHttp\Client;
use Symfony\Component\Process\Process;

class Fetcher
{
    public function fetch(): void
    {
        $c = new Client();
        $p = new Process(['ls']);
    }
}
PHP);

        $detector = new PhantomDependencyDetector;
        $result = $detector->detect($this->workspace, $packageDir);

        $this->assertSame('failed', $result['status']);
        $this->assertSame(['guzzlehttp/guzzle'], $result['undeclared_packages']);
        $this->assertCount(1, $result['violations']);
        $this->assertSame('GuzzleHttp\Client', $result['violations'][0]['class']);
        $this->assertSame('guzzlehttp/guzzle', $result['violations'][0]['package']);
    }

    /**
     * @param  list<array<string, mixed>>  $packages
     */
    private function createInstalledJson(array $packages): void
    {
        $vendorComposer = $this->workspace.'/vendor/composer';
        mkdir($vendorComposer, 0777, true);
        file_put_contents($vendorComposer.'/installed.json', json_encode([
            'packages' => $packages,
        ]));
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
