<?php

declare(strict_types=1);

namespace AlexKassel\DevKit;

use Illuminate\Support\Facades\File;
use JsonException;
use RuntimeException;
use stdClass;

class WorkspaceInstaller
{
    /** @return array{schema_version: int, status: string, dry_run: bool, files: list<string>} */
    public function install(string $root, bool $dryRun = false, bool $force = false): array
    {
        $root = rtrim($root, '/\\');
        if (! is_dir($root)) {
            throw new RuntimeException('Host directory does not exist: '.$root);
        }

        $lockPath = $root.DIRECTORY_SEPARATOR.'.composer-manifest.lock';

        return FileLock::run($lockPath, function () use ($root, $force, $dryRun): array {
            return $this->doInstall($root, $force, $dryRun);
        });
    }

    /**
     * @return array{schema_version: int, status: string, dry_run: bool, files: list<string>}
     */
    private function doInstall(string $root, bool $force, bool $dryRun): array
    {
        foreach (['composer.json', '.gitignore', 'packages'] as $path) {
            if (is_link($root.'/'.$path)) {
                throw new RuntimeException('Refusing to modify a linked workspace path: '.$path);
            }
        }

        $composerPath = $root.'/composer.json';
        $original = $this->read($composerPath);
        try {
            $manifest = json_decode($original, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid composer.json: '.$exception->getMessage(), 0, $exception);
        }
        if (! $manifest instanceof stdClass) {
            throw new RuntimeException('composer.json must contain a JSON object.');
        }

        $before = json_encode($manifest, JSON_THROW_ON_ERROR);
        $this->prepareRepository($manifest);
        $this->prepareScripts($manifest);
        $writes = [];
        if ($before !== json_encode($manifest, JSON_THROW_ON_ERROR)) {
            $newline = str_contains($original, "\r\n") ? "\r\n" : "\n";
            $writes['composer.json'] = str_replace("\n", $newline, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)).$newline;
        }

        $ignorePath = $root.'/.gitignore';
        $ignore = File::exists($ignorePath) ? $this->read($ignorePath) : '';
        $rawRules = preg_split('/\r?\n/', $ignore) ?: [];
        $rules = array_values(array_filter($rawRules, fn (string $line): bool => trim($line) !== '' && ! str_starts_with($line, '#')));
        if (! in_array($rules === [] ? '' : end($rules), ['/packages/*/*', '/packages/*/*/'], true)) {
            $newline = str_contains($ignore, "\r\n") ? "\r\n" : "\n";
            $writes['.gitignore'] = $ignore.($ignore !== '' && ! str_ends_with($ignore, "\n") ? $newline : '').'/packages/*/*'.$newline;
        }

        $this->collectAgentWrites($root, $writes, $force);

        $packages = $root.'/packages';
        if (File::exists($packages) && ! File::isDirectory($packages)) {
            throw new RuntimeException('packages exists but is not a directory.');
        }
        $createDirectory = ! File::isDirectory($packages);
        foreach (array_keys($writes) as $path) {
            if ((File::exists($root.'/'.$path) && ! is_writable($root.'/'.$path)) || ! is_writable($root)) {
                throw new RuntimeException('Workspace file is not writable: '.$path);
            }
        }
        if ($createDirectory && ! is_writable($root)) {
            throw new RuntimeException('Cannot create the packages directory.');
        }

        $files = array_keys($writes);
        if ($createDirectory) {
            $files[] = 'packages/';
        }
        if (! $dryRun) {
            foreach ($writes as $path => $contents) {
                $dir = dirname($root.'/'.$path);
                try {
                    File::ensureDirectoryExists($dir);
                } catch (\Throwable) {
                    throw new RuntimeException('Could not create directory: '.$dir);
                }
                $this->write($root.'/'.$path, $contents);
            }
            if ($createDirectory) {
                try {
                    File::ensureDirectoryExists($packages);
                } catch (\Throwable) {
                    throw new RuntimeException('Could not create packages/. Earlier file changes may have been applied; rerun after fixing permissions.');
                }
            }
        }

        return [
            'schema_version' => 1,
            'status' => $files === [] ? 'unchanged' : ($dryRun ? 'planned' : 'installed'),
            'dry_run' => $dryRun,
            'files' => $files,
        ];
    }

    private function prepareRepository(stdClass $manifest): void
    {
        $repositories = property_exists($manifest, 'repositories') ? $manifest->repositories : [];
        if (! is_array($repositories) && ! $repositories instanceof stdClass) {
            throw new RuntimeException('repositories must be a list or a named object.');
        }
        $entries = (array) $repositories;
        $matches = [];
        foreach ($entries as $key => $repository) {
            if ($repository instanceof stdClass && ($repository->type ?? null) === 'path'
                && in_array($repository->url ?? null, ['packages/*/*', './packages/*/*'], true)) {
                $matches[] = $key;
            }
        }
        if (count($matches) > 1) {
            throw new RuntimeException('Multiple matching packages/*/* path repositories are configured.');
        }

        $existing = $matches === [] ? null : $entries[$matches[0]];
        if ($existing instanceof stdClass) {
            if (property_exists($existing, 'canonical') && $existing->canonical !== true) {
                throw new RuntimeException('The existing packages/*/* repository must have canonical=true or leave it default.');
            }
            if (property_exists($existing, 'options') && $existing->options instanceof stdClass
                && property_exists($existing->options, 'symlink') && $existing->options->symlink !== true) {
                throw new RuntimeException('The existing packages/*/* repository must have options.symlink=true or leave it default.');
            }
            $target = $existing;
            unset($entries[$matches[0]]);
        } else {
            $target = (object) [
                'type' => 'path',
                'url' => 'packages/*/*',
            ];
        }

        $target->canonical = true;
        $target->options = property_exists($target, 'options') && $target->options instanceof stdClass
            ? $target->options
            : new stdClass;
        $target->options->symlink = true;

        if (is_array($repositories)) {
            $manifest->repositories = array_values([$target, ...$entries]);
        } else {
            $named = ['dev-kit-workspace' => $target];
            foreach ($entries as $key => $repository) {
                if ($key !== 'dev-kit-workspace') {
                    $named[$key] = $repository;
                }
            }
            $manifest->repositories = (object) $named;
        }
    }

    private function prepareScripts(stdClass $manifest): void
    {
        if (! property_exists($manifest, 'scripts')) {
            $manifest->scripts = new stdClass;
        }

        $shortcuts = [
            'pkg:check' => '@php artisan pkg:check',
            'pkg:clone' => '@php artisan pkg:clone',
            'pkg:install' => '@php artisan pkg:install',
            'pkg:list' => '@php artisan pkg:list',
            'pkg:make' => '@php artisan pkg:make',
            'pkg:readme' => '@php artisan pkg:readme',
            'pkg:release-check' => '@php artisan pkg:release-check',
            'pkg:remove' => '@php artisan pkg:remove',
            'pkg:sync' => '@php artisan pkg:sync',
            'test:tooling' => '@php vendor/bin/phpunit -c packages/alex-kassel/dev-kit/phpunit.xml',
        ];

        if ($manifest->scripts instanceof stdClass) {
            foreach ($shortcuts as $name => $command) {
                if (! property_exists($manifest->scripts, $name)) {
                    $manifest->scripts->{$name} = $command;
                }
            }
        } elseif (is_array($manifest->scripts)) {
            $scriptsObj = (object) $manifest->scripts;
            foreach ($shortcuts as $name => $command) {
                if (! property_exists($scriptsObj, $name)) {
                    $scriptsObj->{$name} = $command;
                }
            }
            $manifest->scripts = $scriptsObj;
        }
    }

    /**
     * @param  array<string, string>  $writes
     */
    private function collectAgentWrites(string $root, array &$writes, bool $force): void
    {
        $resourcesDir = __DIR__.'/../resources/agents';
        if (! File::isDirectory($resourcesDir)) {
            return;
        }

        $agentsFile = $resourcesDir.'/AGENTS.md';
        if (File::exists($agentsFile)) {
            $content = $this->read($agentsFile);
            $targetPath = $root.'/AGENTS.md';
            if (! File::exists($targetPath)) {
                $writes['AGENTS.md'] = $content;
            } else {
                $existing = $this->read($targetPath);
                $isDefaultSkeletonPlaceholder = str_contains($existing, '<laravel-boost-guidelines>')
                    || str_contains($existing, 'laravel/boost')
                    || str_contains($existing, '# Laravel Boost');

                if (($force || $isDefaultSkeletonPlaceholder) && $existing !== $content) {
                    $writes['AGENTS.md'] = $content;
                }
            }
        }

        $skillsDir = $resourcesDir.'/skills';
        if (File::isDirectory($skillsDir)) {
            $this->scanAgentDirectory($skillsDir, $skillsDir, $root, $writes, $force);
        }
    }

    /**
     * @param  array<string, string>  $writes
     */
    private function scanAgentDirectory(string $baseDir, string $currentDir, string $root, array &$writes, bool $force): void
    {
        $files = File::allFiles($baseDir);
        foreach ($files as $file) {
            $sourcePath = $file->getPathname();
            $relativePath = $file->getRelativePathname();
            $relTarget = '.agents/skills/'.str_replace('\\', '/', $relativePath);
            $targetPath = $root.'/'.$relTarget;
            $content = $this->read($sourcePath);

            if (! File::exists($targetPath)) {
                $writes[$relTarget] = $content;
            } elseif ($force && $this->read($targetPath) !== $content) {
                $writes[$relTarget] = $content;
            }
        }
    }

    private function read(string $path): string
    {
        return FileIO::read($path);
    }

    private function write(string $path, string $contents): void
    {
        FileIO::write($path, $contents);
    }
}
