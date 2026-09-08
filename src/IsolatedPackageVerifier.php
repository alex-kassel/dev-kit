<?php

declare(strict_types=1);

namespace AlexKassel\DevKit;

use Illuminate\Support\Facades\Process;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

class IsolatedPackageVerifier
{
    /** @return array{name: string, status: string, exit_code: int, command: string, output: string, duration_ms: int} */
    public function verify(string $packagePath): array
    {
        $started = microtime(true);
        $temporary = sys_get_temp_dir().'/dev-kit-isolated-'.bin2hex(random_bytes(12));
        $project = $temporary.'/project';
        $output = [];
        $exitCode = 0;
        $filesystem = new Filesystem;
        try {
            $filesystem->mkdir($project);
            $this->export($packagePath, $project);
            $manifest = json_decode(FileIO::read($project.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($manifest)) {
                throw new RuntimeException('Package composer.json must contain an object.');
            }
            foreach (($manifest['repositories'] ?? []) as $repository) {
                if (is_array($repository) && ($repository['type'] ?? '') === 'path') {
                    throw new RuntimeException('Standalone verification rejects path repositories; dependencies must be independently installable.');
                }
            }
            $configuration = is_file($project.'/phpunit.xml') ? 'phpunit.xml' : 'phpunit.xml.dist';
            if (! is_file($project.'/'.$configuration)) {
                throw new RuntimeException('Standalone verification requires phpunit.xml or phpunit.xml.dist.');
            }
            // Keep dependency installation and Composer configuration outside the host,
            // while allowing Composer to reuse cached package archives.
            $environment = [
                'COMPOSER' => false, 'COMPOSER_HOME' => $temporary.'/composer-home',
                'COMPOSER_VENDOR_DIR' => $project.'/vendor', 'COMPOSER_BIN_DIR' => $project.'/vendor/bin',
                'COMPOSER_ROOT_VERSION' => false, 'COMPOSER_NO_DEV' => false,
                'APP_ENV' => 'testing', 'CACHE_STORE' => 'array', 'CACHE_DRIVER' => 'array',
                'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
            ];
            $cacheDir = $this->resolveComposerCacheDir();
            if ($cacheDir !== null) {
                $environment['COMPOSER_CACHE_DIR'] = $cacheDir;
            }
            $output[] = $this->run(['composer', 'install', '--prefer-dist', '--no-interaction', '--no-progress'], $project, $environment);
            $binary = is_file($project.'/tests/Pest.php') ? 'pest' : 'phpunit';
            if (! is_file($project.'/vendor/bin/'.$binary)) {
                throw new RuntimeException('Declare the test runner in package require-dev; the isolated vendor has no '.$binary.'.');
            }
            $output[] = $this->run([PHP_BINARY, $project.'/vendor/bin/'.$binary, '-c', $configuration, '--fail-on-empty-test-suite'], $project, $environment);
        } catch (\Throwable $exception) {
            $exitCode = 1;
            $output[] = $exception->getMessage();
        } finally {
            try {
                // This exact, randomly generated child is the only cleanup target.
                FileIO::removeDirectory($temporary);
            } catch (\Throwable $exception) {
                $exitCode = 1;
                $output[] = 'Cannot clean isolated workspace '.$temporary.': '.$exception->getMessage();
            }
        }

        return [
            'name' => 'Standalone Installation and Tests', 'status' => $exitCode === 0 ? 'passed' : 'failed',
            'exit_code' => $exitCode, 'command' => 'composer install; isolated package test runner',
            'output' => implode("\n", $output), 'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }

    /** Export current tracked and non-ignored files, including tests excluded from distribution archives. */
    public function export(string $packagePath, string $destination): void
    {
        $source = realpath($packagePath);
        if ($source === false) {
            throw new RuntimeException('Package directory does not exist.');
        }
        $top = trim($this->run(['git', 'rev-parse', '--show-toplevel'], $source));
        if (realpath($top) !== $source) {
            throw new RuntimeException('Standalone verification requires an independent Git checkout.');
        }
        $listed = $this->run(['git', 'ls-files', '--cached', '--others', '--exclude-standard', '-z'], $source);
        $filesystem = new Filesystem;
        foreach (array_unique(explode("\0", $listed)) as $relative) {
            if ($relative === '' || preg_match('~(^|/)(?:vendor|node_modules|\.git|\.phpunit\.cache)(/|$)~', $relative)
                || $relative === 'composer.lock' || $relative === '.env') {
                continue;
            }
            if (str_starts_with($relative, '/') || in_array('..', explode('/', $relative), true)) {
                throw new RuntimeException('Invalid export path: '.$relative);
            }
            $path = $source.'/'.$relative;
            if (! file_exists($path) && ! is_link($path)) {
                continue; // A tracked file deleted in the current working tree.
            }
            $resolved = realpath($path);
            if (is_link($path) || $resolved === false || ! is_file($path)
                || str_replace('\\', '/', $resolved) !== str_replace('\\', '/', $path)) {
                throw new RuntimeException('Cannot export linked or non-file package content: '.$relative);
            }
            $filesystem->copy($path, $destination.'/'.$relative, true);
        }
    }

    /** @param list<string> $command
     * @param  array<string, string|false>  $environment
     */
    private function run(array $command, string $directory, array $environment = []): string
    {
        $pendingProcess = Process::path($directory)->timeout(600);
        if ($environment !== []) {
            $pendingProcess = $pendingProcess->env($environment);
        }
        $result = $pendingProcess->run($command);
        if (! $result->successful()) {
            throw new RuntimeException($result->errorOutput() ?: $result->output());
        }

        return $result->output();
    }

    private function resolveComposerCacheDir(): ?string
    {
        $envCache = getenv('COMPOSER_CACHE_DIR');
        if (is_string($envCache) && $envCache !== '' && is_dir($envCache)) {
            return $envCache;
        }

        $home = getenv('COMPOSER_HOME');
        if (is_string($home) && $home !== '' && is_dir($home.'/cache')) {
            return $home.'/cache';
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $localAppData = getenv('LOCALAPPDATA');
            if (is_string($localAppData) && is_dir($localAppData.'/Composer')) {
                return $localAppData.'/Composer';
            }
        } else {
            $homeDir = getenv('HOME');
            if (is_string($homeDir)) {
                if (is_dir($homeDir.'/.cache/composer')) {
                    return $homeDir.'/.cache/composer';
                }
                if (is_dir($homeDir.'/.composer/cache')) {
                    return $homeDir.'/.composer/cache';
                }
            }
        }

        return null;
    }
}
