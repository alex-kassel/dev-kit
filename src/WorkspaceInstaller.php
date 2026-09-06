<?php

namespace AlexKassel\DevKit;

use JsonException;
use RuntimeException;
use stdClass;

class WorkspaceInstaller
{
    /** @return array{schema_version: int, status: string, dry_run: bool, files: list<string>} */
    public function install(string $root, bool $dryRun = false): array
    {
        $root = rtrim($root, '/\\');
        if (! is_dir($root)) {
            throw new RuntimeException('Host directory does not exist: '.$root);
        }

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
        $writes = [];
        if ($before !== json_encode($manifest, JSON_THROW_ON_ERROR)) {
            $newline = str_contains($original, "\r\n") ? "\r\n" : "\n";
            $writes['composer.json'] = str_replace("\n", $newline, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)).$newline;
        }

        $ignorePath = $root.'/.gitignore';
        $ignore = file_exists($ignorePath) ? $this->read($ignorePath) : '';
        $rules = array_values(array_filter(preg_split('/\r?\n/', $ignore), fn (string $line): bool => trim($line) !== '' && ! str_starts_with($line, '#')));
        if (! in_array($rules === [] ? '' : end($rules), ['/packages/*/*', '/packages/*/*/'], true)) {
            $newline = str_contains($ignore, "\r\n") ? "\r\n" : "\n";
            $writes['.gitignore'] = $ignore.($ignore !== '' && ! str_ends_with($ignore, "\n") ? $newline : '').'/packages/*/*'.$newline;
        }

        $packages = $root.'/packages';
        if (file_exists($packages) && ! is_dir($packages)) {
            throw new RuntimeException('packages exists but is not a directory.');
        }
        $createDirectory = ! is_dir($packages);
        foreach (array_keys($writes) as $path) {
            if ((file_exists($root.'/'.$path) && ! is_writable($root.'/'.$path)) || ! is_writable($root)) {
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
                $this->write($root.'/'.$path, $contents);
            }
            if ($createDirectory && ! @mkdir($packages, 0777, false) && ! is_dir($packages)) {
                throw new RuntimeException('Could not create packages/. Earlier file changes may have been applied; rerun after fixing permissions.');
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

    private function read(string $path): string
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Cannot read file: '.$path);
        }

        return $contents;
    }

    private function write(string $path, string $contents): void
    {
        $result = @file_put_contents($path, $contents);
        if ($result === false) {
            throw new RuntimeException('Cannot write file: '.$path);
        }
    }
}
