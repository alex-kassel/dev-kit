<?php

declare(strict_types=1);

namespace AlexKassel\DevKit\Console;

use AlexKassel\DevKit\OrganizationResolver;
use AlexKassel\DevKit\PackageInventory;
use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use RuntimeException;

class ListPackagesCommand extends Command
{
    protected $signature = 'pkg:list {--json : Emit a machine-readable result}';

    protected $description = 'List local packages and their Composer installation state';

    public function handle(PackageInventory $inventory, OrganizationResolver $orgResolver): int
    {
        $root = $this->laravel->basePath();

        try {
            $installed = [];
            foreach (InstalledVersions::getInstalledPackages() as $name) {
                $path = InstalledVersions::getInstallPath($name);
                if ($path !== null) {
                    $installed[$name] = ['path' => $path, 'version' => InstalledVersions::getPrettyVersion($name)];
                }
            }

            /** @var Repository $config */
            $config = $this->laravel->make('config');
            $configuredOrgs = $config->get('dev-kit.organizations', []);
            $organizations = $orgResolver->resolve(
                $root,
                null,
                null,
                is_array($configuredOrgs) ? $configuredOrgs : []
            );

            $packages = $inventory->inspect($root, $organizations, $installed);
        } catch (RuntimeException $exception) {
            if ($this->option('json')) {
                $this->line(json_encode([
                    'schema_version' => 1,
                    'status' => 'error',
                    'error' => ['code' => 'PACKAGE_LIST_FAILED', 'message' => $exception->getMessage()],
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } else {
                $this->error($exception->getMessage());
            }

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'schema_version' => 1,
                'status' => 'ok',
                'organizations' => $organizations,
                'packages' => $packages,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } elseif ($packages === []) {
            $this->info('No local packages found.');
        } else {
            $this->table(['Package', 'Path', 'Owned', 'Composer', 'Installed version'], array_map(fn (array $package): array => [
                $package['name'],
                $package['path'],
                $package['owned'] ? 'yes' : 'no',
                $package['linked'] ? 'local linked' : ($package['installed'] ? 'installed elsewhere' : 'not installed'),
                $package['installed_version'] ?? '-',
            ], $packages));
        }

        return self::SUCCESS;
    }
}
