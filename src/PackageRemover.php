<?php

declare(strict_types=1);

namespace AlexKassel\DevKit;

use RuntimeException;
use Symfony\Component\Process\Process;

class PackageRemover
{
    /**
     * @return array{
     *     schema_version: int,
     *     status: string,
     *     package: string,
     *     path: string,
     *     unlinked: bool,
     *     deleted: bool
     * }
     */
    public function remove(
        string $root,
        string $package,
        bool $unlinkOnly = false,
        bool $force = false,
        bool $noSync = false
    ): array {
        $root = realpath($root);
        if ($root === false) {
            throw new RuntimeException('Host directory does not exist.');
        }

        $rawPackage = trim($package, '/\\ ');
        $pkgPath = $root.DIRECTORY_SEPARATOR.'packages'.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $rawPackage);

        if (! is_dir($pkgPath)) {
            throw new RuntimeException("Package directory not found: packages/{$rawPackage}");
        }

        $shouldDelete = ! $unlinkOnly;

        if ($shouldDelete) {
            $this->assertSafeToDelete($pkgPath, $rawPackage, $force);
            $this->deleteDirectory($pkgPath);
        }

        $unlinked = false;
        if (! $noSync) {
            $this->unlinkPackageManifest($root, $rawPackage);
            $unlinked = true;
        }

        return [
            'schema_version' => 1,
            'status' => 'removed',
            'package' => $rawPackage,
            'path' => 'packages/'.str_replace('\\', '/', $rawPackage),
            'unlinked' => $unlinked,
            'deleted' => $shouldDelete,
        ];
    }

    private function assertSafeToDelete(string $pkgPath, string $package, bool $force): void
    {
        if ($force) {
            return;
        }

        $gitDir = $pkgPath.DIRECTORY_SEPARATOR.'.git';
        if (! is_dir($gitDir)) {
            return;
        }

        // 1. Check for dirty / uncommitted working tree
        $statusProcess = new Process(['git', 'status', '--porcelain'], $pkgPath);
        $statusProcess->run();
        $statusOutput = trim($statusProcess->getOutput());
        if ($statusOutput !== '') {
            throw new RuntimeException("Package '{$package}' has uncommitted or untracked changes. Commit, stash, or pass --force to remove.");
        }

        // 2. Check for unpushed commits if upstream exists
        $upstreamCheck = new Process(['git', 'rev-parse', '--verify', '--quiet', '@{u}'], $pkgPath);
        $upstreamCheck->run();
        if ($upstreamCheck->getExitCode() === 0) {
            $logProcess = new Process(['git', 'log', '@{u}..HEAD', '--oneline'], $pkgPath);
            $logProcess->run();
            $logOutput = trim($logProcess->getOutput());
            if ($logOutput !== '') {
                throw new RuntimeException("Package '{$package}' has unpushed commits. Push changes or pass --force to remove.");
            }
        }
    }

    private function deleteDirectory(string $path): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $process = new Process(['cmd.exe', '/c', 'rd', '/s', '/q', $path]);
            $process->run();
            if ($process->getExitCode() === 0 && ! is_dir($path)) {
                return;
            }
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                @rmdir($file->getRealPath());
            } else {
                @unlink($file->getRealPath());
            }
        }

        @rmdir($path);
        if (is_dir($path)) {
            throw new RuntimeException("Failed to completely delete directory: {$path}");
        }
    }

    private function unlinkPackageManifest(string $root, string $package): void
    {
        $composerPath = $root.DIRECTORY_SEPARATOR.'composer.json';
        if (! file_exists($composerPath)) {
            return;
        }

        $lockPath = $root.DIRECTORY_SEPARATOR.'.composer-manifest.lock';
        FileLock::run($lockPath, function () use ($composerPath, $package): void {
            $contents = @file_get_contents($composerPath);
            if ($contents === false) {
                return;
            }

            try {
                /** @var array<string, mixed>|null $data */
                $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return;
            }

            if (! is_array($data)) {
                return;
            }

            $changed = false;
            if (isset($data['require']) && is_array($data['require']) && array_key_exists($package, $data['require'])) {
                unset($data['require'][$package]);
                $changed = true;
            }

            if (isset($data['require-dev']) && is_array($data['require-dev']) && array_key_exists($package, $data['require-dev'])) {
                unset($data['require-dev'][$package]);
                $changed = true;
            }

            if ($changed) {
                $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                if ($encoded !== false) {
                    file_put_contents($composerPath, $encoded."\n");
                }
            }
        });
    }
}
