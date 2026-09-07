<?php

declare(strict_types=1);

namespace AlexKassel\DevKit;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use JsonException;
use RuntimeException;

class PackageScaffolder
{
    /**
     * @return array{schema_version: int, status: string, package: string, path: string, archetype: string, dry_run: bool, git: string, root_registration: string, files_count: int, files: list<string>}
     */
    public function scaffold(
        string $root,
        string $package,
        string $archetype = 'library',
        bool $initGit = false,
        bool $registerInRoot = false,
        bool $dryRun = false
    ): array {
        $root = realpath($root);
        if ($root === false) {
            throw new RuntimeException('Host directory does not exist.');
        }

        if (! in_array($archetype, ['library', 'engine', 'domain'], true)) {
            throw new RuntimeException("Invalid archetype '{$archetype}'. Supported archetypes: library, engine, domain");
        }

        $rawPackage = trim($package, '/\\ ');
        $normalizedPackage = str_replace('\\', '/', $rawPackage);

        if (! preg_match('~^[a-z0-9]+(?:[_.-][a-z0-9]+)*/[a-z0-9]+(?:[_.-][a-z0-9]+)*$~Di', $normalizedPackage)) {
            throw new RuntimeException("Invalid package identifier '{$rawPackage}'. Must be in 'vendor/package-name' format.");
        }

        $parts = explode('/', $normalizedPackage);
        $vendor = strtolower($parts[0]);
        $packageName = strtolower($parts[1]);

        $packageDir = $root.DIRECTORY_SEPARATOR.'packages'.DIRECTORY_SEPARATOR.$vendor.DIRECTORY_SEPARATOR.$packageName;
        $relPackageDir = "packages/{$vendor}/{$packageName}";

        if (File::isDirectory($packageDir) && count(File::directories($packageDir) ?: []) + count(File::files($packageDir) ?: []) > 0) {
            throw new RuntimeException("Package directory '{$relPackageDir}' already exists and is not empty.");
        }

        $vendorNamespace = $this->toPascalCase($vendor);
        $packageNamespace = $this->toPascalCase($packageName);
        $fullNamespace = "{$vendorNamespace}\\{$packageNamespace}";

        $filesToCreate = $this->generateTemplates($root, $vendor, $packageName, $fullNamespace, $packageNamespace, $archetype);

        $createdFiles = [];

        if (! $dryRun) {
            try {
                File::ensureDirectoryExists($packageDir);
            } catch (\Throwable) {
                throw new RuntimeException("Cannot create package directory: {$packageDir}");
            }

            foreach ($filesToCreate as $relFile => $content) {
                $absFilePath = $packageDir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relFile);
                FileIO::write($absFilePath, $content);
                $createdFiles[] = "{$relPackageDir}/{$relFile}";
            }

            $gitStatus = 'skipped';
            if ($initGit) {
                $gitDir = $packageDir.DIRECTORY_SEPARATOR.'.git';
                if (! is_dir($gitDir)) {
                    $result = Process::path($packageDir)->run(['git', 'init', '-b', 'main']);
                    $gitStatus = $result->successful() ? 'initialized' : 'failed';
                } else {
                    $gitStatus = 'already_initialized';
                }
            }

            $rootRegStatus = 'skipped';
            if ($registerInRoot) {
                $rootRegStatus = $this->registerInRootComposer($root, "{$vendor}/{$packageName}");
            }
        } else {
            foreach (array_keys($filesToCreate) as $relFile) {
                $createdFiles[] = "{$relPackageDir}/{$relFile}";
            }
            $gitStatus = $initGit ? 'dry_run_git_init' : 'skipped';
            $rootRegStatus = $registerInRoot ? 'dry_run_register' : 'skipped';
        }

        return [
            'schema_version' => 1,
            'status' => 'created',
            'package' => "{$vendor}/{$packageName}",
            'path' => $relPackageDir,
            'archetype' => $archetype,
            'dry_run' => $dryRun,
            'git' => $gitStatus,
            'root_registration' => $rootRegStatus,
            'files_count' => count($createdFiles),
            'files' => $createdFiles,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function generateTemplates(
        string $root,
        string $vendor,
        string $packageName,
        string $fullNamespace,
        string $packageNamespace,
        string $archetype
    ): array {
        $year = date('Y');
        $author = $this->resolveAuthor($vendor);
        $files = [];

        // 1. composer.json
        $keywords = ['laravel', 'package'];
        if ($archetype === 'engine') {
            $keywords[] = 'engine';
        } elseif ($archetype === 'domain') {
            $keywords[] = 'domain';
        }

        $composerData = [
            'name' => "{$vendor}/{$packageName}",
            'description' => "{$packageNamespace} {$archetype} package for Laravel ecosystem.",
            'type' => 'library',
            'license' => 'MIT',
            'keywords' => $keywords,
            'authors' => [
                $author,
            ],
            'require' => [
                'php' => '^8.2 || ^8.3 || ^8.4',
                'illuminate/support' => '^11.0 || ^12.0 || ^13.0',
            ],
            'autoload' => [
                'psr-4' => [
                    "{$fullNamespace}\\" => 'src/',
                ],
            ],
            'autoload-dev' => [
                'psr-4' => [
                    "{$fullNamespace}\\Tests\\" => 'tests/',
                ],
            ],
            'extra' => [
                'laravel' => [
                    'providers' => [
                        "{$fullNamespace}\\{$packageNamespace}ServiceProvider",
                    ],
                ],
            ],
            'minimum-stability' => 'dev',
            'prefer-stable' => true,
        ];

        $files['composer.json'] = json_encode($composerData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

        $replacements = [
            '{{ namespace }}' => $fullNamespace,
            '{{ namespace_escaped }}' => addslashes($fullNamespace),
            '{{ class }}' => $packageNamespace,
            '{{ package }}' => "{$vendor}/{$packageName}",
            '{{ vendor }}' => $vendor,
            '{{ package_name }}' => $packageName,
            '{{ year }}' => (string) $year,
            '{{ author_name }}' => $author['name'],
            '{{ author_email }}' => $author['email'] ?? '',
        ];

        $files["src/{$packageNamespace}ServiceProvider.php"] = $this->renderStub($root, 'ServiceProvider.php.stub', $replacements);
        $files['tests/bootstrap.php'] = $this->renderStub($root, 'bootstrap.php.stub', $replacements);
        $files['tests/TestCase.php'] = $this->renderStub($root, 'TestCase.php.stub', $replacements);
        $files['tests/Unit/ServiceProviderTest.php'] = $this->renderStub($root, 'ServiceProviderTest.php.stub', $replacements);
        $files['phpunit.xml'] = $this->renderStub($root, 'phpunit.xml.stub', $replacements);
        $files['phpstan.neon'] = $this->renderStub($root, 'phpstan.neon.stub', $replacements);
        $files['.gitignore'] = $this->renderStub($root, 'gitignore.stub', $replacements);
        $files['.gitattributes'] = $this->renderStub($root, 'gitattributes.stub', $replacements);
        $files['LICENSE'] = $this->renderStub($root, 'LICENSE.stub', $replacements);
        $files['CHANGELOG.md'] = $this->renderStub($root, 'CHANGELOG.md.stub', $replacements);
        $files['README.md'] = $this->renderStub($root, 'README.md.stub', $replacements);

        return $files;
    }

    /**
     * @param  array<string, string>  $replacements
     */
    private function renderStub(string $root, string $stubName, array $replacements): string
    {
        $publishedPath = $root.DIRECTORY_SEPARATOR.'stubs'.DIRECTORY_SEPARATOR.'dev-kit'.DIRECTORY_SEPARATOR.$stubName;
        if (File::exists($publishedPath)) {
            $content = File::get($publishedPath);
        } else {
            $stubPath = __DIR__.'/../stubs/'.$stubName;
            if (! File::exists($stubPath)) {
                throw new RuntimeException("Stub file not found: {$stubName}");
            }
            $content = File::get($stubPath);
        }

        return str_replace(array_keys($replacements), array_values($replacements), $content);
    }

    private function registerInRootComposer(string $root, string $package): string
    {
        $composerPath = $root.DIRECTORY_SEPARATOR.'composer.json';
        if (! File::exists($composerPath)) {
            return 'missing_root_composer';
        }

        $lockPath = $root.DIRECTORY_SEPARATOR.'.composer-manifest.lock';

        return FileLock::run($lockPath, function () use ($composerPath, $package): string {
            try {
                $content = File::get($composerPath);
            } catch (\Throwable) {
                return 'unreadable_root_composer';
            }

            try {
                /** @var array<string, mixed>|null $json */
                $json = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return 'invalid_root_composer';
            }

            if (! is_array($json)) {
                return 'invalid_root_composer';
            }

            /** @var array<string, string> $require */
            $require = isset($json['require']) && is_array($json['require']) ? $json['require'] : [];
            if (isset($require[$package])) {
                return 'already_registered';
            }

            $require[$package] = '@dev';
            $json['require'] = $require;

            try {
                $encoded = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return 'invalid_root_composer';
            }

            FileIO::write($composerPath, $encoded."\n", $content);

            return 'registered';
        });
    }

    /**
     * @return array{name: string, role: string, email?: string}
     */
    private function resolveAuthor(string $vendor): array
    {
        $nameResult = Process::run(['git', 'config', 'user.name']);
        $name = trim($nameResult->output());

        if ($name === '') {
            $name = $this->toPascalCase($vendor);
        }

        $emailResult = Process::run(['git', 'config', 'user.email']);
        $email = trim($emailResult->output());

        $author = [
            'name' => $name,
            'role' => 'Developer',
        ];

        if ($email !== '') {
            $author['email'] = $email;
        }

        return $author;
    }

    private function toPascalCase(string $input): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $input)));
    }
}
