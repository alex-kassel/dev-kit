<?php

declare(strict_types=1);

namespace AlexKassel\DevKit\Console;

use AlexKassel\DevKit\WorkspaceInstaller;
use Illuminate\Console\Command;
use RuntimeException;

class InstallCommand extends Command
{
    protected $signature = 'pkg:install
        {--local : Also clone dev-kit into packages/ for local development}
        {--branch=main : Git branch to clone when --local is used}
        {--dry-run : Preview changes without writing files}
        {--force : Overwrite existing agent configuration and skill files}
        {--json : Emit a machine-readable result}';

    protected $description = 'Prepare local package directories, Composer repository, scripts and agent skills';

    public function handle(WorkspaceInstaller $installer): int
    {
        $isLocal = (bool) $this->option('local');
        $rawBranch = $this->option('branch');
        $branch = is_string($rawBranch) && $rawBranch !== '' ? $rawBranch : 'main';
        $isDryRun = (bool) $this->option('dry-run');

        try {
            $result = $installer->install(
                $this->laravel->basePath(),
                $isDryRun,
                (bool) $this->option('force')
            );
        } catch (RuntimeException $exception) {
            if ($this->option('json')) {
                $this->line(json_encode([
                    'schema_version' => 1,
                    'status' => 'error',
                    'error' => ['code' => 'INSTALL_FAILED', 'message' => $exception->getMessage()],
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } else {
                $this->error($exception->getMessage());
            }

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(match ($result['status']) {
                'unchanged' => 'Workspace is already prepared.',
                'planned' => 'Planned changes: '.implode(', ', $result['files']),
                default => 'Workspace prepared: '.implode(', ', $result['files']),
            });
        }

        if ($isLocal && ! $isDryRun) {
            $this->call('pkg:clone', [
                'package' => 'alex-kassel/dev-kit',
                '--branch' => $branch,
                '--update' => true,
            ]);
        }

        return self::SUCCESS;
    }
}
