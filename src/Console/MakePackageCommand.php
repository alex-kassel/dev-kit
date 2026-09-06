<?php

namespace AlexKassel\DevKit\Console;

use AlexKassel\DevKit\PackageScaffolder;
use Illuminate\Console\Command;
use RuntimeException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class MakePackageCommand extends Command
{
    protected $signature = 'pkg:make
        {package? : Vendor and package name (e.g. alex-kassel/cache-engine)}
        {--archetype=library : Package archetype: library, engine, or domain}
        {--git : Initialize Git repository in package directory}
        {--register : Register package in root composer.json require}
        {--dry-run : Simulate creation without writing files}
        {--json : Emit a machine-readable result}';

    protected $aliases = ['package:make'];

    protected $description = 'Deterministically scaffold a new package with standard structure and 12 files';

    public function handle(PackageScaffolder $scaffolder): int
    {
        $rawPackage = $this->argument('package');
        $package = is_string($rawPackage) && $rawPackage !== '' ? $rawPackage : null;
        $rawArchetype = $this->option('archetype');
        $archetype = is_string($rawArchetype) ? $rawArchetype : 'library';
        $initGit = (bool) $this->option('git');
        $register = (bool) $this->option('register');
        $dryRun = (bool) $this->option('dry-run');
        $isJson = (bool) $this->option('json');

        $root = $this->laravel->basePath();

        if ($package === null) {
            if ($isJson) {
                $this->line(json_encode([
                    'schema_version' => 1,
                    'status' => 'error',
                    'error' => ['code' => 'MISSING_PACKAGE', 'message' => 'Missing package argument.'],
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

                return self::FAILURE;
            }

            // Interactive Prompts Wizard
            $package = text(
                label: 'Package name in vendor/name format (e.g. alex-kassel/cache-engine):',
                required: true,
                validate: fn (string $value): ?string => preg_match('~^[a-z0-9]+(?:[_.-][a-z0-9]+)*/[a-z0-9]+(?:[_.-][a-z0-9]+)*$~D', trim($value))
                    ? null
                    : 'Must be in vendor/package format (lowercase letters, numbers, dashes).'
            );

            $selectedArchetype = select(
                label: 'Choose package archetype:',
                options: ['library', 'engine', 'domain'],
                default: 'library'
            );
            $archetype = is_string($selectedArchetype) ? $selectedArchetype : (string) $selectedArchetype;

            $initGit = confirm(
                label: 'Initialize independent Git repository in package?',
                default: true
            );

            $register = confirm(
                label: 'Register package in root composer.json?',
                default: false
            );
        }

        try {
            $result = $scaffolder->scaffold(
                $root,
                $package,
                $archetype,
                $initGit,
                $register,
                $dryRun
            );
        } catch (RuntimeException $exception) {
            if ($isJson) {
                $this->line(json_encode([
                    'schema_version' => 1,
                    'status' => 'error',
                    'error' => ['code' => 'SCAFFOLD_FAILED', 'message' => $exception->getMessage()],
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } else {
                $this->error($exception->getMessage());
            }

            return self::FAILURE;
        }

        if ($isJson) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderSummary($result);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{schema_version: int, status: string, package: string, path: string, archetype: string, dry_run: bool, git: string, root_registration: string, files_count: int, files: list<string>}  $result
     */
    private function renderSummary(array $result): void
    {
        $this->line('============================================================');
        $this->line($result['dry_run']
            ? " 🔍 [DRY-RUN] Package Scaffolding: {$result['path']}"
            : " 📦 Package Scaffolding Created: {$result['path']}");
        $this->line('============================================================');
        $this->newLine();

        $this->line("Package:   <fg=cyan>{$result['package']}</>");
        $this->line("Archetype: <fg=yellow>{$result['archetype']}</>");
        $this->line("Git Init:  {$result['git']}");
        $this->line("Root Reg:  {$result['root_registration']}");
        $this->newLine();

        $this->line("Generated files ({$result['files_count']}):");
        foreach ($result['files'] as $file) {
            $this->line("  <fg=green>✔</> {$file}");
        }

        $this->newLine();
        $this->line('------------------------------------------------------------');
        if ($result['dry_run']) {
            $this->info('🔍 Dry-run complete. No files were written to disk.');
        } else {
            $this->info('✔ Package initialized successfully.');
            $this->line(' Next step: verify package quality with:');
            $this->line("   <fg=yellow>php artisan pkg:check {$result['package']}</>");
        }
        $this->line('============================================================');
    }
}
