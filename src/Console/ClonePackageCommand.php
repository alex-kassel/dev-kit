<?php

declare(strict_types=1);

namespace AlexKassel\DevKit\Console;

use AlexKassel\DevKit\OrganizationResolver;
use AlexKassel\DevKit\PackageCloner;
use AlexKassel\DevKit\PackageLocalizer;
use AlexKassel\DevKit\PackageSynchronizer;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use RuntimeException;
use Symfony\Component\Process\Process;

class ClonePackageCommand extends Command
{
    protected $signature = 'pkg:clone
        {package : Composer package name}
        {--branch= : Explicit branch to clone (defaults to remote default branch)}
        {--recursive : Localize owned require dependencies}
        {--org= : Additional comma-separated trusted organization vendors}
        {--no-sync : Do not automatically register cloned package in root composer.json}
        {--update : Run composer update for the cloned package to establish symlink}
        {--no-update : Do not run composer update}
        {--json : Emit a machine-readable result}';

    protected $description = 'Clone a package, optionally localizing owned dependencies, and synchronizing workspace';

    public function handle(
        PackageCloner $cloner,
        PackageLocalizer $localizer,
        OrganizationResolver $resolver,
        PackageSynchronizer $synchronizer
    ): int {
        /** @var ConfigRepository $config */
        $config = $this->laravel->make('config');
        $defaultPattern = (string) $config->get('dev-kit.default_source_pattern', '');
        $packageArg = $this->argument('package');
        $rawPackageName = is_string($packageArg) ? $packageArg : '';
        $branchOpt = $this->option('branch');
        $branch = (is_string($branchOpt) && trim($branchOpt) !== '') ? trim($branchOpt) : null;
        $sources = $config->get('dev-kit.sources', []);
        $configuredOrgs = $config->get('dev-kit.organizations', []);
        /** @var string|null $cliOrg */
        $cliOrg = $this->option('org');

        $root = $this->laravel->basePath();
        $packageName = $resolver->resolvePackageName(
            $root,
            $rawPackageName,
            $cliOrg,
            is_array($configuredOrgs) ? $configuredOrgs : []
        );

        $organizations = $resolver->resolve(
            $root,
            $packageName,
            $cliOrg,
            is_array($configuredOrgs) ? $configuredOrgs : []
        );

        $pattern = $defaultPattern !== '' ? $defaultPattern : null;
        $isJson = (bool) $this->option('json');
        $noSync = (bool) $this->option('no-sync');
        $shouldUpdate = (bool) $this->option('update');
        $noUpdate = (bool) $this->option('no-update');

        if ($this->option('recursive')) {
            try {
                $result = $localizer->localize(
                    $root,
                    $packageName,
                    $branch,
                    $sources,
                    $organizations,
                    $pattern
                );
            } catch (RuntimeException $exception) {
                return $this->handleError($exception, $isJson);
            }

            if (! $noSync) {
                $synchronizer->sync($root);
            }

            $updated = false;
            if (! $noUpdate && ($shouldUpdate || ! $isJson)) {
                if (! $isJson) {
                    $this->info('Running composer update to link localized packages...');
                }
                $packageNames = array_values(array_unique(array_map(
                    fn (array $pkg): string => (string) $pkg['name'],
                    $result['packages']
                )));

                $process = new Process(['composer', 'update', ...$packageNames, '--with-all-dependencies', '--prefer-dist', '--no-interaction'], $root);
                $process->setTimeout(600.0);
                if (! $isJson) {
                    $process->run(function (string $type, string $buffer): void {
                        $this->output->write($buffer);
                    });
                } else {
                    $process->run();
                }
                $updated = ($process->getExitCode() === 0);
            }

            $result['composer_updated'] = $updated;

            if ($isJson) {
                $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } else {
                foreach ($result['packages'] as $package) {
                    $this->line($package['action'].': '.$package['name'].' ('.$package['branch'].')');
                }
                $this->line($noSync ? 'Localization complete.' : 'Localization complete. Workspace composer.json synchronized.');
                if ($updated) {
                    $this->info('Composer workspace updated and dependencies linked locally.');
                }
            }

            return self::SUCCESS;
        }

        try {
            $result = $cloner->clonePackage(
                $root,
                $packageName,
                $branch,
                $sources,
                $pattern
            );
        } catch (RuntimeException $exception) {
            return $this->handleError($exception, $isJson);
        }

        $synced = false;
        if (! $noSync) {
            $synchronizer->sync($root);
            $synced = true;
        }

        $updated = false;
        if ($shouldUpdate && ! $noUpdate) {
            if (! $isJson) {
                $this->info('Running composer update for '.$packageName.'...');
            }
            $process = new Process(['composer', 'update', $packageName, '--with-all-dependencies', '--prefer-dist', '--no-interaction'], $root);
            $process->setTimeout(300.0);
            if (! $isJson) {
                $process->run(function (string $type, string $buffer): void {
                    $this->output->write($buffer);
                });
            } else {
                $process->run();
            }
            $updated = ($process->getExitCode() === 0);
        }

        $result['composer_synced'] = $synced;
        $result['composer_updated'] = $updated;

        if ($isJson) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('Cloned '.$result['name'].' into '.$result['path'].' on '.$result['branch']);
            $this->line('Commit: '.$result['commit']);
            if ($synced) {
                $this->line('Workspace composer.json synchronized.');
            }
            if ($updated) {
                $this->info('Composer package updated and linked locally.');
            }
            foreach (['require' => (array) $result['require'], 'require_dev' => (array) $result['require_dev']] as $section => $deps) {
                foreach ($deps as $name => $constraint) {
                    $this->line($section.': '.$name.' '.$constraint);
                }
            }
        }

        return self::SUCCESS;
    }

    private function handleError(RuntimeException $exception, bool $isJson): int
    {
        if ($isJson) {
            $this->line(json_encode([
                'schema_version' => 1,
                'status' => 'error',
                'error' => [
                    'code' => 'PACKAGE_CLONE_FAILED',
                    'message' => $exception->getMessage(),
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->error($exception->getMessage());
        }

        return self::FAILURE;
    }
}
