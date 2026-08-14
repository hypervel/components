<?php

declare(strict_types=1);

namespace Hypervel\Foundation;

use Hyperf\Contract\ApplicationInterface;
use Hyperf\ExceptionHandler\Listener\ErrorExceptionHandler;
use Hyperf\Server\Listener\InitProcessTitleListener;
use Hypervel\Console\ApplicationFactory;
use Hypervel\Dispatcher\Pipeline as DispatcherPipeline;
use Hypervel\Foundation\Console\Commands\AboutCommand;
use Hypervel\Foundation\Console\Commands\ConfigShowCommand;
use Hypervel\Foundation\Console\Commands\ServerReloadCommand;
use Hypervel\Foundation\Console\Commands\VendorPublishCommand;
use Hypervel\Foundation\Http\Pipeline as HttpPipeline;
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
                // Converts throwables into responses inside the middleware
                // pipeline, so middleware can observe the status the client
                // actually receives.
                DispatcherPipeline::class => HttpPipeline::class,
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
