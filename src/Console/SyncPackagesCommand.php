<?php

declare(strict_types=1);

namespace AlexKassel\DevKit\Console;

use AlexKassel\DevKit\PackageSynchronizer;
use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Process\Process;

class SyncPackagesCommand extends Command
{
    protected $signature = 'pkg:sync
        {--clean : Remove all @dev packages from root composer.json}
        {--filter= : Filter packages by glob pattern (e.g. *engine*, acme/*)}
        {--dump : Automatically run composer dump-autoload after sync}
        {--no-dump : Do not prompt or run composer dump-autoload}
        {--dry-run : Preview changes without modifying composer.json}
        {--json : Output machine-readable JSON summary}';

    protected $description = 'Synchronize local packages in packages/* with root composer.json require';

    public function handle(PackageSynchronizer $synchronizer): int
    {
        $isClean = (bool) $this->option('clean');
        $rawFilter = $this->option('filter');
        $filter = is_string($rawFilter) && $rawFilter !== '' ? $rawFilter : null;
        $isDryRun = (bool) $this->option('dry-run');
        $isJson = (bool) $this->option('json');
        $autoDump = (bool) $this->option('dump');
        $noDump = (bool) $this->option('no-dump');

        try {
            $result = $synchronizer->sync(
                $this->laravel->basePath(),
                $isClean,
                $filter,
                $isDryRun
            );
        } catch (RuntimeException $e) {
            if ($isJson) {
                $this->line(json_encode([
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } else {
                $this->error('Error: '.$e->getMessage());
            }

            return self::FAILURE;
        }

        // Handle dump-autoload
        $dumpExecuted = false;
        $hasChanges = count($result['added']) > 0 || count($result['removed']) > 0;

        if (! $isDryRun && $hasChanges) {
            if ($autoDump || (! $noDump && ! $isJson && $this->confirm('Run composer dump-autoload now?', true))) {
                $process = new Process(['composer', 'dump-autoload'], $this->laravel->basePath());
                $process->run();
                $dumpExecuted = ($process->getExitCode() === 0);
            }
        }

        $result['dump_executed'] = $dumpExecuted;

        if ($isJson) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('============================================================');
        $this->line($isDryRun ? ' 🔍 [DRY-RUN] Package Synchronization' : ' 🔄 Package Synchronization Completed');
        $this->line('============================================================');
        $this->newLine();

        $this->line('Discovered on disk:  '.$result['total_discovered_on_disk'].' package(s)');
        $this->line('Registered in root:  '.$result['registered_count'].' package(s)');
        $this->newLine();

        if (! empty($result['added'])) {
            $this->info('➕ Added ('.count($result['added']).'):');
            foreach ($result['added'] as $pkg) {
                $this->line("   + {$pkg}");
            }
            $this->newLine();
        }

        if (! empty($result['removed'])) {
            $this->warn('➖ Removed ('.count($result['removed']).'):');
            foreach ($result['removed'] as $pkg) {
                $this->line("   - {$pkg}");
            }
            $this->newLine();
        }

        if (! empty($result['retained'])) {
            $this->line('✔ Retained: '.count($result['retained']).' package(s)');
        }

        if (! $hasChanges) {
            $this->info('✨ composer.json is already up to date. No changes made.');
        }

        if ($dumpExecuted) {
            $this->info('🚀 composer dump-autoload completed successfully.');
        }

        $this->line('============================================================');

        return self::SUCCESS;
    }
}
