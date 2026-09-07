<?php

declare(strict_types=1);

namespace AlexKassel\DevKit;

use RuntimeException;
use Symfony\Component\Process\Process;

class PackageRemover
{
    public function __construct(private readonly PackagePathResolver $paths = new PackagePathResolver) {}

    /** @return array{schema_version: int, status: string, package: string, path: string, unlinked: bool, deleted: bool} */
    public function remove(string $root, string $package, bool $unlinkOnly = false, bool $force = false, bool $noSync = false): array
    {
        $path = $this->paths->resolve($root, $package);
        $root = realpath($root);
        if ($root === false) {
            throw new RuntimeException('Host directory does not exist.');
        }
        $name = basename(dirname($path)).'/'.basename($path);

        return FileLock::run($root.'/.composer-manifest.lock', function () use ($root, $name, $path, $unlinkOnly, $force, $noSync): array {
            // Validate every prerequisite before any irreversible filesystem operation.
            $manifestPath = $root.'/composer.json';
            $original = $noSync ? null : FileIO::read($manifestPath);
            try {
                $manifest = $original === null ? null : json_decode($original, false, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new RuntimeException('Invalid root composer.json: '.$exception->getMessage(), 0, $exception);
            }
            if (! $noSync && ! $manifest instanceof \stdClass) {
                throw new RuntimeException('Root composer.json must contain a JSON object.');
            }
            if (! $noSync && is_link($manifestPath)) {
                throw new RuntimeException('Refusing to modify a linked composer.json.');
            }
            $changed = false;
            if ($manifest instanceof \stdClass) {
                foreach (['require', 'require-dev'] as $section) {
                    if (isset($manifest->{$section})) {
                        if (! $manifest->{$section} instanceof \stdClass) {
                            throw new RuntimeException('Invalid dependency section: '.$section);
                        }
                        if (property_exists($manifest->{$section}, $name)) {
                            unset($manifest->{$section}->{$name});
                            $changed = true;
                        }
                    }
                }
            }
            if (! $unlinkOnly) {
                $this->assertSafeToDelete($path, $name, $force);
            }
            // A failed manifest update must never destroy a checkout.
            if ($changed) {
                FileIO::write($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n", $original);
            }
            try {
                if (! $unlinkOnly) {
                    $this->paths->resolve($root, $name);
                    FileIO::removeDirectory($path);
                }
            } catch (\Throwable $exception) {
                if ($changed && $original !== null) {
                    FileIO::write($manifestPath, $original);
                }
                throw new RuntimeException('Removal failed; inspect the checkout for partial deletion. Manifest changes were rolled back: '.$exception->getMessage(), 0, $exception);
            }

            return ['schema_version' => 1, 'status' => 'removed', 'package' => $name, 'path' => 'packages/'.$name,
                'unlinked' => ! $noSync, 'deleted' => ! $unlinkOnly];
        });
    }

    private function assertSafeToDelete(string $path, string $name, bool $force): void
    {
        if ($force) {
            return;
        }
        $top = trim($this->git($path, ['rev-parse', '--show-toplevel']));
        if (realpath($top) !== realpath($path)) {
            throw new RuntimeException('Package is not an independent Git checkout. Use --force to remove: '.$name);
        }
        if (trim($this->git($path, ['status', '--porcelain', '--untracked-files=all', '--ignored'])) !== '') {
            throw new RuntimeException('Package has uncommitted, untracked or ignored files. Preserve them or use --force: '.$name);
        }
        // No upstream, detached HEAD and failed Git commands all stop removal.
        $this->git($path, ['rev-parse', '--verify', '@{u}']);
        if (trim($this->git($path, ['log', '@{u}..HEAD', '--oneline'])) !== '') {
            throw new RuntimeException('Package has unpushed commits: '.$name);
        }
        if (trim($this->git($path, ['stash', 'list'])) !== '') {
            throw new RuntimeException('Package has stashed changes: '.$name);
        }
        if (trim($this->git($path, ['rev-list', '--branches', '--tags', '--not', '--remotes'])) !== '') {
            throw new RuntimeException('Package contains local commits not covered by remote tracking refs: '.$name);
        }
    }

    /** @param list<string> $arguments */
    private function git(string $path, array $arguments): string
    {
        $process = new Process(['git', ...$arguments], $path);
        $process->setTimeout(30);
        try {
            $process->mustRun();
        } catch (\Throwable $exception) {
            throw new RuntimeException('Cannot establish safe removal: '.$exception->getMessage(), 0, $exception);
        }

        return $process->getOutput();
    }
}
