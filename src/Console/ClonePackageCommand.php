<?php

declare(strict_types=1);

namespace AlexKassel\DevKit\Console;

use AlexKassel\DevKit\OrganizationResolver;
use AlexKassel\DevKit\PackageCloner;
use AlexKassel\DevKit\PackageLocalizer;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use RuntimeException;

class ClonePackageCommand extends Command
{
    protected $signature = 'pkg:clone
        {package : Composer package name}
        {--branch= : Explicit branch to clone}
        {--recursive : Localize owned require dependencies with one explicit dev-* alternative}
        {--org= : Additional comma-separated trusted organization vendors}
        {--json : Emit a machine-readable result}';

    protected $description = 'Clone a package, optionally localizing owned dependencies, without Composer installation';

    public function handle(PackageCloner $cloner, PackageLocalizer $localizer, OrganizationResolver $resolver): int
    {
        /** @var ConfigRepository $config */
        $config = $this->laravel->make('config');
        $defaultPattern = (string) $config->get('dev-kit.default_source_pattern', '');
        $packageArg = $this->argument('package');
        $packageName = is_string($packageArg) ? $packageArg : '';
        $branchOpt = $this->option('branch');
        $branch = is_string($branchOpt) ? $branchOpt : '';
        $sources = $config->get('dev-kit.sources', []);
        $configuredOrgs = $config->get('dev-kit.organizations', []);
        /** @var string|null $cliOrg */
        $cliOrg = $this->option('org');

        $root = $this->laravel->basePath();
        $organizations = $resolver->resolve(
            $root,
            $packageName,
            $cliOrg,
            is_array($configuredOrgs) ? $configuredOrgs : []
        );

        $pattern = $defaultPattern !== '' ? $defaultPattern : null;
        $isJson = (bool) $this->option('json');

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

            if ($isJson) {
                $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } else {
                foreach ($result['packages'] as $package) {
                    $this->line($package['action'].': '.$package['name'].' ('.$package['branch'].')');
                }
                $this->line('Localization complete. Composer dependencies have not been installed.');
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

        if ($isJson) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('Cloned '.$result['name'].' into '.$result['path'].' on '.$result['branch']);
            $this->line('Commit: '.$result['commit']);
            $this->line('Composer dependencies have not been installed.');
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
