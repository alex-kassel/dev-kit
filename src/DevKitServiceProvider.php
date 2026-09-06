<?php

namespace AlexKassel\DevKit;

use AlexKassel\DevKit\Console\ClonePackageCommand;
use AlexKassel\DevKit\Console\InstallCommand;
use AlexKassel\DevKit\Console\ListPackagesCommand;
use Illuminate\Support\ServiceProvider;

class DevKitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/dev-kit.php', 'dev-kit');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/dev-kit.php' => $this->app->configPath('dev-kit.php'),
            ], 'dev-kit-config');

            $this->commands([
                InstallCommand::class,
                ListPackagesCommand::class,
                ClonePackageCommand::class,
            ]);
        }
    }
}
