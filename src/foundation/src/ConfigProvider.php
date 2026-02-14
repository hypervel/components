<?php

declare(strict_types=1);

namespace Hypervel\Foundation;

use Hyperf\Contract\ApplicationInterface;
use Hypervel\ExceptionHandler\Listener\ErrorExceptionHandler;
use Hypervel\Server\Listener\InitProcessTitleListener;
use Hypervel\Console\ApplicationFactory;
use Hypervel\Foundation\Console\Commands\AboutCommand;
use Hypervel\Foundation\Console\Commands\ConfigShowCommand;
use Hypervel\Foundation\Console\Commands\ServerReloadCommand;
use Hypervel\Foundation\Console\Commands\VendorPublishCommand;
use Hypervel\Foundation\Listeners\ReloadDotenvAndConfig;
use Hypervel\Foundation\Listeners\SetProcessTitle;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                ApplicationInterface::class => ApplicationFactory::class,
                InitProcessTitleListener::class => SetProcessTitle::class,
            ],
            'listeners' => [
                ErrorExceptionHandler::class,
                ReloadDotenvAndConfig::class,
            ],
            'commands' => [
                AboutCommand::class,
                ConfigShowCommand::class,
                ServerReloadCommand::class,
                VendorPublishCommand::class,
            ],
            'publish' => [
                [
                    'id' => 'config',
                    'description' => 'The configuration file of foundation.',
                    'source' => __DIR__ . '/../publish/app.php',
                    'destination' => BASE_PATH . '/config/autoload/app.php',
                ],
            ],
        ];
    }
}
