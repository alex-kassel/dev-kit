<?php

namespace AlexKassel\DevKit\Console;

use AlexKassel\DevKit\PackageCloner;
use AlexKassel\DevKit\PackageLocalizer;
use Illuminate\Console\Command;
use RuntimeException;

class ClonePackageCommand extends Command
{
    protected $signature = 'pkg:clone {package : Composer package name} {--branch= : Explicit branch to clone} {--recursive : Localize owned require dependencies with one explicit dev-* alternative} {--json : Emit a machine-readable result}';

    protected $aliases = ['package:clone'];

    protected $description = 'Clone a package, optionally localizing owned dependencies, without Composer installation';

    public function handle(PackageCloner $cloner, PackageLocalizer $localizer): int
    {
        try {
            $defaultPattern = (string) $this->laravel['config']->get('dev-kit.default_source_pattern', '');
            $arguments = [
                $this->laravel->basePath(),
                $this->argument('package'),
                (string) $this->option('branch'),
                $this->laravel['config']->get('dev-kit.sources', []),
                $defaultPattern !== '' ? $defaultPattern : null,
            ];
            $result = $this->option('recursive')
                ? $localizer->localize(
                    $arguments[0],
                    $arguments[1],
                    $arguments[2],
                    $arguments[3],
                    $this->laravel['config']->get('dev-kit.organizations', []),
                    $arguments[4]
                )
                : $cloner->clonePackage(...$arguments);
        } catch (RuntimeException $exception) {
            if ($this->option('json')) {
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

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } elseif ($this->option('recursive')) {
            foreach ($result['packages'] as $package) {
                $this->line($package['action'].': '.$package['name'].' ('.$package['branch'].')');
            }
            $this->line('Localization complete. Composer dependencies have not been installed.');
        } else {
            $this->info('Cloned '.$result['name'].' into '.$result['path'].' on '.$result['branch']);
            $this->line('Commit: '.$result['commit']);
            $this->line('Composer dependencies have not been installed.');
            foreach (['require', 'require_dev'] as $section) {
                foreach ($result[$section] as $name => $constraint) {
                    $this->line($section.': '.$name.' '.$constraint);
                }
            }
        }

        return self::SUCCESS;
    }
}
