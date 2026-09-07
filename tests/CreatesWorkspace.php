<?php

declare(strict_types=1);

namespace AlexKassel\DevKit\Tests;

use AlexKassel\DevKit\FileIO;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

trait CreatesWorkspace
{
    private string $workspace;

    private function createWorkspace(): void
    {
        $this->workspace = sys_get_temp_dir().'/dev-kit-regression-'.bin2hex(random_bytes(10));
        (new Filesystem)->mkdir($this->workspace);
    }

    private function removeWorkspace(): void
    {
        $resolved = realpath($this->workspace);
        if ($resolved === false || dirname($resolved) !== realpath(sys_get_temp_dir()) || ! str_starts_with(basename($resolved), 'dev-kit-regression-')) {
            throw new RuntimeException('Refusing cleanup outside generated workspace.');
        }
        FileIO::removeDirectory($resolved);
    }

    private function git(string $path, string ...$arguments): string
    {
        $process = new Process(['git', ...$arguments], $path, [
            'GIT_AUTHOR_NAME' => 'DevKit Tests', 'GIT_AUTHOR_EMAIL' => 'tests@example.invalid',
            'GIT_COMMITTER_NAME' => 'DevKit Tests', 'GIT_COMMITTER_EMAIL' => 'tests@example.invalid',
        ]);
        $process->mustRun();

        return trim($process->getOutput());
    }

    private function initializeRepository(string $path): void
    {
        $this->git($path, 'init', '-b', 'main');
        $this->git($path, 'add', '.');
        $this->git($path, 'commit', '-m', 'test: initial fixture');
    }
}
