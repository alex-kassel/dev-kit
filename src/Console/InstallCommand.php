<?php

declare(strict_types=1);

namespace AlexKassel\DevKit\Console;

use AlexKassel\DevKit\WorkspaceInstaller;
use Illuminate\Console\Command;
use RuntimeException;

class InstallCommand extends Command
{
    protected $signature = 'pkg:install {--dry-run : Preview changes without writing files} {--force : Overwrite existing agent configuration and skill files} {--json : Emit a machine-readable result}';

    protected $description = 'Prepare local package directories, Composer repository, scripts and agent skills';

    public function handle(WorkspaceInstaller $installer): int
    {
        try {
            $result = $installer->install(
                $this->laravel->basePath(),
                (bool) $this->option('dry-run'),
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

        return self::SUCCESS;
    }
}
