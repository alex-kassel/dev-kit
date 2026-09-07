<?php

declare(strict_types=1);

namespace AlexKassel\DevKit\Console;

use AlexKassel\DevKit\OrganizationResolver;
use AlexKassel\DevKit\PackageRemover;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use RuntimeException;

class RemovePackageCommand extends Command
{
    protected $signature = 'pkg:remove
        {package : Composer package name (e.g. vendor/package)}
        {--unlink : Only unlink package from workspace, leave directory on disk}
        {--force : Force removal even if git working tree has uncommitted or unpushed changes}
        {--no-sync : Do not synchronize workspace composer.json after removal}
        {--json : Output result as JSON}';

    protected $description = 'Safely remove or unlink a package from the workspace with Git hygiene checks';

    public function handle(
        PackageRemover $remover,
        OrganizationResolver $resolver
    ): int {
        /** @var ConfigRepository $config */
        $config = $this->laravel->make('config');
        $packageArg = $this->argument('package');
        $rawPackageName = is_string($packageArg) ? $packageArg : '';
        $configuredOrgs = $config->get('dev-kit.organizations', []);

        $root = $this->laravel->basePath();
        $packageName = $resolver->resolvePackageName(
            $root,
            $rawPackageName,
            null,
            is_array($configuredOrgs) ? $configuredOrgs : []
        );

        $unlinkOnly = (bool) $this->option('unlink');
        $force = (bool) $this->option('force');
        $noSync = (bool) $this->option('no-sync');
        $isJson = (bool) $this->option('json');

        if (! $unlinkOnly && ! $force && ! $isJson) {
            if (! $this->confirm("Are you sure you want to permanently delete 'packages/{$packageName}'?", false)) {
                $this->info('Removal cancelled.');

                return self::SUCCESS;
            }
        }

        try {
            $result = $remover->remove($root, $packageName, $unlinkOnly, $force, $noSync);
        } catch (RuntimeException $exception) {
            if ($isJson) {
                $this->line(json_encode([
                    'schema_version' => 1,
                    'status' => 'error',
                    'error' => [
                        'code' => 'PACKAGE_REMOVAL_FAILED',
                        'message' => $exception->getMessage(),
                    ],
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } else {
                $this->error($exception->getMessage());
            }

            return self::FAILURE;
        }

        if ($isJson) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            if ($result['deleted']) {
                $this->info("Package '{$packageName}' deleted from disk and unlinked from workspace.");
            } else {
                $this->info("Package '{$packageName}' unlinked from workspace (files retained on disk).");
            }
        }

        return self::SUCCESS;
    }
}
