<?php

namespace AlexKassel\DevKit;

use RuntimeException;
use Symfony\Component\Process\Process;

class PackageVerifier
{
    /**
     * @param  list<string>|null  $only
     * @return array{package: string, path: string, status: string, summary: array{passed: int, failed: int, skipped: int, not_configured: int}, checks: array<string, array{name: string, status: string, exit_code: int, command: string, output: string, duration_ms: int}>}
     */
    public function verify(string $root, string $package, bool $fix = false, ?array $only = null): array
    {
        $root = realpath($root);
        if ($root === false) {
            throw new RuntimeException('Host directory does not exist.');
        }

        $packagePath = $this->resolvePackagePath($root, $package);
        $relPackagePath = str_replace([$root.DIRECTORY_SEPARATOR, $root.'/'], '', $packagePath);
        $relPackagePath = str_replace('\\', '/', $relPackagePath);

        $checks = [];
        $runCheck = fn (string $key): bool => $only === null || in_array($key, $only, true);

        // 1. Composer Validate
        if ($runCheck('composer')) {
            $checks['composer'] = $this->checkComposer($root, $packagePath, $relPackagePath);
        }

        // 2. Pint Code Style
        if ($runCheck('pint')) {
            $checks['pint'] = $this->checkPint($root, $relPackagePath, $fix);
        }

        // 3. PHPStan Static Analysis
        if ($runCheck('phpstan')) {
            $checks['phpstan'] = $this->checkPhpStan($root, $packagePath, $relPackagePath);
        }

        // 4. Automated Tests
        if ($runCheck('tests')) {
            $checks['tests'] = $this->checkTests($root, $packagePath, $relPackagePath);
        }

        $passedCount = 0;
        $failedCount = 0;
        $skippedCount = 0;
        $notConfiguredCount = 0;

        foreach ($checks as $check) {
            match ($check['status']) {
                'passed' => $passedCount++,
                'failed' => $failedCount++,
                'skipped' => $skippedCount++,
                'not_configured' => $notConfiguredCount++,
                default => null,
            };
        }

        $overallStatus = $failedCount === 0 ? 'passed' : 'failed';

        return [
            'package' => $package,
            'path' => $relPackagePath,
            'status' => $overallStatus,
            'summary' => [
                'passed' => $passedCount,
                'failed' => $failedCount,
                'skipped' => $skippedCount,
                'not_configured' => $notConfiguredCount,
            ],
            'checks' => $checks,
        ];
    }

    /**
     * @param  list<string>|null  $only
     * @return array{schema_version: int, status: string, total: int, passed: int, failed: int, results: list<array<string, mixed>>}
     */
    public function verifyAll(string $root, bool $fix = false, ?array $only = null): array
    {
        $packageRoot = rtrim($root, '/\\').'/packages';
        if (! is_dir($packageRoot)) {
            return [
                'schema_version' => 1,
                'status' => 'ok',
                'total' => 0,
                'passed' => 0,
                'failed' => 0,
                'results' => [],
            ];
        }

        $packages = [];
        $vendors = @scandir($packageRoot) ?: [];
        foreach ($vendors as $vendor) {
            if ($vendor === '.' || $vendor === '..' || ! is_dir($packageRoot.'/'.$vendor)) {
                continue;
            }
            $dirs = @scandir($packageRoot.'/'.$vendor) ?: [];
            foreach ($dirs as $dir) {
                if ($dir === '.' || $dir === '..' || ! is_dir($packageRoot.'/'.$vendor.'/'.$dir)) {
                    continue;
                }
                if (file_exists($packageRoot.'/'.$vendor.'/'.$dir.'/composer.json')) {
                    $packages[] = $vendor.'/'.$dir;
                }
            }
        }

        sort($packages);

        $results = [];
        $passedTotal = 0;
        $failedTotal = 0;

        foreach ($packages as $pkg) {
            $result = $this->verify($root, $pkg, $fix, $only);
            if ($result['status'] === 'passed') {
                $passedTotal++;
            } else {
                $failedTotal++;
            }
            $results[] = $result;
        }

        return [
            'schema_version' => 1,
            'status' => $failedTotal === 0 ? 'passed' : 'failed',
            'total' => count($packages),
            'passed' => $passedTotal,
            'failed' => $failedTotal,
            'results' => $results,
        ];
    }

    private function resolvePackagePath(string $root, string $package): string
    {
        $rawPackage = trim($package, '/\\ ');
        $candidates = [
            $root.DIRECTORY_SEPARATOR.$rawPackage,
            $root.DIRECTORY_SEPARATOR.'packages'.DIRECTORY_SEPARATOR.$rawPackage,
        ];

        foreach ($candidates as $candidate) {
            if (is_dir($candidate)) {
                return realpath($candidate) ?: $candidate;
            }
        }

        throw new RuntimeException("Package directory not found for '{$package}'. Checked: packages/{$rawPackage}");
    }

    /** @return array{name: string, status: string, exit_code: int, command: string, output: string, duration_ms: int} */
    private function checkComposer(string $root, string $packagePath, string $relPackagePath): array
    {
        $composerJson = $packagePath.DIRECTORY_SEPARATOR.'composer.json';
        if (! file_exists($composerJson)) {
            return [
                'name' => 'Composer Validation',
                'status' => 'not_configured',
                'exit_code' => 0,
                'command' => 'none',
                'output' => 'No composer.json found in package directory.',
                'duration_ms' => 0,
            ];
        }

        $command = ['composer', 'validate', '--strict', str_replace('\\', '/', $relPackagePath.'/composer.json')];

        return $this->runCommand($command, $root, 'Composer Validation');
    }

    /** @return array{name: string, status: string, exit_code: int, command: string, output: string, duration_ms: int} */
    private function checkPint(string $root, string $relPackagePath, bool $fix): array
    {
        $pintBin = $this->resolveBinary($root, 'pint');
        if ($pintBin === null) {
            return [
                'name' => 'Code Style (Pint)',
                'status' => 'skipped',
                'exit_code' => 0,
                'command' => 'none',
                'output' => 'Pint binary not found in vendor/bin.',
                'duration_ms' => 0,
            ];
        }

        $command = [$pintBin, str_replace('\\', '/', $relPackagePath)];
        if (! $fix) {
            $command[] = '--test';
        }

        return $this->runCommand($command, $root, 'Code Style (Pint)');
    }

    /** @return array{name: string, status: string, exit_code: int, command: string, output: string, duration_ms: int} */
    private function checkPhpStan(string $root, string $packagePath, string $relPackagePath): array
    {
        $phpstanBin = $this->resolveBinary($root, 'phpstan');
        $srcDir = $packagePath.DIRECTORY_SEPARATOR.'src';

        if (! is_dir($srcDir)) {
            return [
                'name' => 'Static Analysis (PHPStan)',
                'status' => 'not_configured',
                'exit_code' => 0,
                'command' => 'none',
                'output' => 'No src/ directory found for static analysis.',
                'duration_ms' => 0,
            ];
        }

        if ($phpstanBin === null) {
            return [
                'name' => 'Static Analysis (PHPStan)',
                'status' => 'skipped',
                'exit_code' => 0,
                'command' => 'none',
                'output' => 'PHPStan binary not found in vendor/bin.',
                'duration_ms' => 0,
            ];
        }

        $stanConfig = $packagePath.DIRECTORY_SEPARATOR.'phpstan.neon';
        $target = str_replace('\\', '/', $relPackagePath.'/src');

        if (file_exists($stanConfig)) {
            $configRel = str_replace('\\', '/', $relPackagePath.'/phpstan.neon');
            $command = [$phpstanBin, 'analyse', $target, '--configuration='.$configRel, '--memory-limit=1G'];
        } else {
            $command = [$phpstanBin, 'analyse', $target, '--level=8', '--memory-limit=1G'];
        }

        return $this->runCommand($command, $root, 'Static Analysis (PHPStan)');
    }

    /** @return array{name: string, status: string, exit_code: int, command: string, output: string, duration_ms: int} */
    private function checkTests(string $root, string $packagePath, string $relPackagePath): array
    {
        $phpunitXml = $packagePath.DIRECTORY_SEPARATOR.'phpunit.xml';
        if (! file_exists($phpunitXml)) {
            return [
                'name' => 'Automated Tests',
                'status' => 'not_configured',
                'exit_code' => 0,
                'command' => 'none',
                'output' => 'No phpunit.xml found in package directory.',
                'duration_ms' => 0,
            ];
        }

        $xmlRel = str_replace('\\', '/', $relPackagePath.'/phpunit.xml');
        $artisanPath = $root.DIRECTORY_SEPARATOR.'artisan';

        if (file_exists($artisanPath)) {
            $command = [PHP_BINARY, 'artisan', 'test', '-c', $xmlRel];
        } else {
            $phpunitBin = $this->resolveBinary($root, 'phpunit') ?? 'phpunit';
            $command = [$phpunitBin, '-c', $xmlRel];
        }

        return $this->runCommand($command, $root, 'Automated Tests');
    }

    /**
     * @param  list<string>  $command
     * @return array{name: string, status: string, exit_code: int, command: string, output: string, duration_ms: int}
     */
    private function runCommand(array $command, string $cwd, string $checkName): array
    {
        $startTime = microtime(true);
        $process = new Process($command, $cwd);
        $process->setTimeout(120.0);

        try {
            $process->run();
            $exitCode = $process->getExitCode() ?? 1;
            $output = trim($process->getOutput()."\n".$process->getErrorOutput());
        } catch (\Throwable $exception) {
            $exitCode = 1;
            $output = 'Process execution failed: '.$exception->getMessage();
        }

        $durationMs = (int) round((microtime(true) - $startTime) * 1000);

        return [
            'name' => $checkName,
            'status' => $exitCode === 0 ? 'passed' : 'failed',
            'exit_code' => $exitCode,
            'command' => implode(' ', array_map('escapeshellarg', $command)),
            'output' => $output,
            'duration_ms' => $durationMs,
        ];
    }

    private function resolveBinary(string $root, string $binName): ?string
    {
        $binDir = $root.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'bin';

        if (PHP_OS_FAMILY === 'Windows') {
            $batPath = $binDir.DIRECTORY_SEPARATOR.$binName.'.bat';
            if (file_exists($batPath)) {
                return $batPath;
            }
        }

        $defaultPath = $binDir.DIRECTORY_SEPARATOR.$binName;
        if (file_exists($defaultPath)) {
            return $defaultPath;
        }

        return null;
    }
}
