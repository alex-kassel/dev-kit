<?php

declare(strict_types=1);

namespace AlexKassel\DevKit\Console;

use AlexKassel\DevKit\OrganizationResolver;
use AlexKassel\DevKit\PackageInventory;
use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use RuntimeException;

use function Termwind\render;

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
            render('<div class="my-1 text-gray-500 italic">No local packages found in workspace.</div>');
        } else {
            $rows = '';
            foreach ($packages as $pkg) {
                $statusBadge = $pkg['linked']
                    ? '<span class="text-green-400 font-bold">local linked</span>'
                    : ($pkg['installed'] ? '<span class="text-yellow-400">installed elsewhere</span>' : '<span class="text-gray-500">not installed</span>');
                $ownedBadge = $pkg['owned'] ? '<span class="text-green-500">yes</span>' : '<span class="text-gray-500">no</span>';
                $version = $pkg['installed_version'] ?? '-';

                $rows .= "<tr>
                    <td class=\"font-bold text-gray-200\">{$pkg['name']}</td>
                    <td class=\"text-gray-400\">{$pkg['path']}</td>
                    <td>{$ownedBadge}</td>
                    <td>{$statusBadge}</td>
                    <td class=\"text-gray-400\">{$version}</td>
                </tr>";
            }

            $total = count($packages);

            render(<<<HTML
                <div class="my-1">
                    <div class="mb-1">
                        <span class="px-1 bg-blue-600 text-white font-bold">LOCAL PACKAGES</span>
                        <span class="ml-1 text-gray-400">Total: {$total}</span>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th class="font-bold text-gray-300">Package</th>
                                <th class="font-bold text-gray-300">Path</th>
                                <th class="font-bold text-gray-300">Owned</th>
                                <th class="font-bold text-gray-300">Composer</th>
                                <th class="font-bold text-gray-300">Installed Version</th>
                            </tr>
                        </thead>
                        <tbody>
                            {$rows}
                        </tbody>
                    </table>
                </div>
            HTML);
        }

        return self::SUCCESS;
    }
}
