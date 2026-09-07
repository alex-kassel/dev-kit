<?php

declare(strict_types=1);
use Illuminate\Container\Container;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Facade;

$candidates = [
    __DIR__.'/../vendor/autoload.php',
    __DIR__.'/../../../../vendor/autoload.php',
];

$autoloader = null;
foreach ($candidates as $candidate) {
    if (file_exists($candidate)) {
        $autoloader = require $candidate;
        break;
    }
}

if ($autoloader === null) {
    throw new RuntimeException('Composer autoloader not found. Run composer install.');
}

$autoloader->addPsr4('AlexKassel\\DevKit\\Tests\\', __DIR__);

if (Facade::getFacadeApplication() === null) {
    $app = new Container;
    $app->singleton('process', fn () => new Factory);
    Facade::setFacadeApplication($app);
}

return $autoloader;
