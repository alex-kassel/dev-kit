<?php

namespace AlexKassel\DevKit\Console;

use AlexKassel\DevKit\WorkspaceInstaller;
use Illuminate\Console\Command;
use RuntimeException;

class InstallCommand extends Command
{
    protected $signature = 'pkg:install {--dry-run : Preview changes without writing files} {--json : Emit a machine-readable result}';

    protected $aliases = ['dev-kit:install'];

    protected $description = 'Prepare local package directories, Composer repository and Git ignore';

    public function handle(WorkspaceInstaller $installer): int
    {
        try {
            $result = $installer->install($this->laravel->basePath(), (bool) $this->option('dry-run'));
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

        return self::SUCCESS;
    }
}
