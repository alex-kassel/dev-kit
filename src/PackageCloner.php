<?php

declare(strict_types=1);

namespace AlexKassel\DevKit;

use JsonException;
use RuntimeException;
use stdClass;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class PackageCloner
{
    public function __construct(private SourceLocator $sources) {}

    /** @return array{schema_version: int, status: string, name: string, path: string, branch: string, commit: string, composer_updated: bool, require: stdClass, require_dev: stdClass} */
    public function clonePackage(string $root, string $package, ?string $branch = null, mixed $sources = [], ?string $defaultPattern = null): array
    {
        $source = $this->sources->resolve($package, $sources, $defaultPattern);
        $hasExplicitBranch = ($branch !== null && trim($branch) !== '');
        $root = realpath($root);
        if ($root === false) {
            throw new RuntimeException('Host directory does not exist.');
        }
        if ($hasExplicitBranch) {
            $this->git(['check-ref-format', '--branch', $branch], $root);
        }
        $destination = $root.'/packages/'.$package;
        if (file_exists($destination) || is_link($destination)) {
            throw new RuntimeException('Local package path already exists; it was not changed: packages/'.$package);
        }
        $this->assertContained($root, dirname($destination));
        $stagingParent = $root.'/storage/app/private/dev-kit/clones';
        $this->assertContained($root, $stagingParent);
        if (! is_dir($stagingParent) && ! @mkdir($stagingParent, 0777, true) && ! is_dir($stagingParent)) {
            throw new RuntimeException('Cannot create clone staging directory.');
        }
        $staging = $stagingParent.'/'.bin2hex(random_bytes(8));

        $buildCloneArgs = function (string $url) use ($hasExplicitBranch, $branch, $staging): array {
            $args = ['-c', 'core.autocrlf=input', 'clone', '--single-branch'];
            if ($hasExplicitBranch && $branch !== null) {
                $args[] = '--branch';
                $args[] = $branch;
            }
            $args[] = '--no-recurse-submodules';
            $args[] = '--';
            $args[] = $url;
            $args[] = $staging;

            return $args;
        };

        try {
            $cloneUrl = $source;
            try {
                $this->git($buildCloneArgs($cloneUrl), $root);
            } catch (RuntimeException $gitException) {
                // If SSH clone failed due to missing key / host verification, fallback to HTTPS if applicable
                $isSsh = str_starts_with($source, 'git@') || str_starts_with($source, 'ssh://');
                if ($isSsh && preg_match('~^(?:git@|ssh://git@)([^:/]+)[:/]([^/]+)/([^/.]+)(?:\.git)?$~', $source, $matches)) {
                    $host = $matches[1];
                    $vendor = $matches[2];
                    $repo = $matches[3];
                    $httpsFallback = "https://{$host}/{$vendor}/{$repo}.git";

                    if (is_dir($staging)) {
                        $this->deleteDirectory($staging);
                    }

                    $cloneUrl = $httpsFallback;
                    $this->git($buildCloneArgs($cloneUrl), $root);
                } else {
                    throw $gitException;
                }
            }

            $manifest = $this->manifest($staging.'/composer.json', $package);
            $commit = trim($this->git(['rev-parse', 'HEAD'], $staging));
            $actualBranch = trim($this->git(['branch', '--show-current'], $staging));
            if ($actualBranch === '') {
                $actualBranch = trim($this->git(['describe', '--tags', '--always'], $staging));
            }
            if ($hasExplicitBranch && $actualBranch !== $branch) {
                throw new RuntimeException('Requested ref did not produce branch '.$branch.'; tags and detached checkouts are not supported by --branch.');
            }
            if (! is_dir(dirname($destination)) && ! @mkdir(dirname($destination), 0777, true) && ! is_dir(dirname($destination))) {
                throw new RuntimeException('Cannot create package vendor directory.');
            }
            if (file_exists($destination)) {
                throw new RuntimeException('Destination appeared while cloning; it was not changed.');
            }
            if (! @rename($staging, $destination)) {
                throw new RuntimeException('Cannot move validated checkout into packages/.');
            }
        } catch (RuntimeException $exception) {
            $retained = is_dir($staging) ? ' New checkout retained for inspection at '.$staging : '';
            throw new RuntimeException($exception->getMessage().$retained, 0, $exception);
        }

        return [
            'schema_version' => 1,
            'status' => 'cloned',
            'name' => $package,
            'path' => 'packages/'.$package,
            'branch' => $actualBranch,
            'commit' => $commit,
            'composer_updated' => false,
            'require' => $manifest->require ?? new stdClass,
            'require_dev' => $manifest->{'require-dev'} ?? new stdClass,
        ];
    }

    /**
     * @return array{name: string, path: string, branch: string, commit: string, require: stdClass, require_dev: stdClass}
     */
    public function inspectCheckout(string $root, string $package): array
    {
        if (! preg_match('~^[a-z0-9]+(?:[_.-][a-z0-9]+)*/[a-z0-9]+(?:[_.-][a-z0-9]+)*$~D', $package)) {
            throw new RuntimeException('Invalid Composer package name: '.$package);
        }
        $root = realpath($root);
        if ($root === false) {
            throw new RuntimeException('Host directory does not exist.');
        }
        $path = $root.'/packages/'.$package;
        if (! is_dir($path)) {
            throw new RuntimeException('Local package path is not a directory: '.$package);
        }
        $this->assertContained($root, $path);
        $top = trim($this->git(['rev-parse', '--show-toplevel'], $path));
        if (realpath($top) !== realpath($path)) {
            throw new RuntimeException('Local package is not an independent Git checkout: '.$package);
        }
        $manifest = $this->manifest($path.'/composer.json', $package);

        return [
            'name' => $package,
            'path' => 'packages/'.$package,
            'branch' => trim($this->git(['branch', '--show-current'], $path)),
            'commit' => trim($this->git(['rev-parse', 'HEAD'], $path)),
            'require' => $manifest->require ?? new stdClass,
            'require_dev' => $manifest->{'require-dev'} ?? new stdClass,
        ];
    }

    private function manifest(string $path, string $expectedPackage): stdClass
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Cloned repository does not contain a readable composer.json.');
        }
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Cannot read cloned composer.json.');
        }
        try {
            $manifest = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid JSON in cloned composer.json: '.$exception->getMessage(), 0, $exception);
        }
        if (! $manifest instanceof stdClass) {
            throw new RuntimeException('Cloned composer.json must contain a JSON object.');
        }
        $name = $manifest->name ?? null;
        if ($name !== $expectedPackage) {
            throw new RuntimeException('Cloned repository Composer name does not match requested package: '.($name ?? 'missing').' !== '.$expectedPackage);
        }

        return $manifest;
    }

    /**
     * @param  list<string>  $arguments
     */
    private function git(array $arguments, string $cwd): string
    {
        $process = new Process(['git', ...$arguments], $cwd);
        $process->setTimeout(60.0);
        try {
            $process->mustRun();
        } catch (ProcessTimedOutException $exception) {
            throw new RuntimeException('Git operation timed out: git '.implode(' ', $arguments), 0, $exception);
        } catch (RuntimeException $exception) {
            $error = trim($process->getErrorOutput());
            if ($error === '') {
                $error = trim($process->getOutput());
            }
            throw new RuntimeException('Git failed (exit '.($process->getExitCode() ?? 1).'): '.$error, 0, $exception);
        }

        return $process->getOutput();
    }

    private function assertContained(string $root, string $path): void
    {
        $normalizedRoot = str_replace('\\', '/', $root);
        $normalizedPath = str_replace('\\', '/', $path);
        if (! str_starts_with($normalizedPath, $normalizedRoot.'/') && $normalizedPath !== $normalizedRoot) {
            throw new RuntimeException('Path traversal detected: '.$path);
        }
    }

    private function deleteDirectory(string $dir): void
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
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
