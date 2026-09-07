<?php

declare(strict_types=1);

namespace AlexKassel\DevKit;

use RuntimeException;
use Symfony\Component\Process\Process;

class PackageRemover
{
    public function __construct(
        private readonly PackageSynchronizer $synchronizer = new PackageSynchronizer
    ) {}

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
            $this->synchronizer->sync($root, clean: true);
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
}
