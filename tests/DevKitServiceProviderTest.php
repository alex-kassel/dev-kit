<?php

declare(strict_types=1);

namespace AlexKassel\DevKit\Tests;

use AlexKassel\DevKit\Console\CheckPackageCommand;
use AlexKassel\DevKit\Console\ClonePackageCommand;
use AlexKassel\DevKit\Console\InstallCommand;
use AlexKassel\DevKit\Console\ListPackagesCommand;
use AlexKassel\DevKit\Console\MakePackageCommand;
use AlexKassel\DevKit\Console\ReadmePackageCommand;
use AlexKassel\DevKit\Console\ReleaseCheckPackageCommand;
use AlexKassel\DevKit\Console\SyncPackagesCommand;
use AlexKassel\DevKit\DevKitServiceProvider;
use Illuminate\Console\Command;
use PHPUnit\Framework\TestCase;

class DevKitServiceProviderTest extends TestCase
{
    public function test_service_provider_class_exists_and_can_be_instantiated(): void
    {
        $this->assertTrue(class_exists(DevKitServiceProvider::class));
    }

    public function test_all_eight_commands_are_valid_console_commands(): void
    {
        $commands = [
            InstallCommand::class,
            ListPackagesCommand::class,
            ClonePackageCommand::class,
            CheckPackageCommand::class,
            MakePackageCommand::class,
            SyncPackagesCommand::class,
            ReadmePackageCommand::class,
            ReleaseCheckPackageCommand::class,
        ];

        foreach ($commands as $commandClass) {
            $this->assertTrue(is_subclass_of($commandClass, Command::class), "{$commandClass} must extend Illuminate\\Console\\Command");
        }
    }
}
