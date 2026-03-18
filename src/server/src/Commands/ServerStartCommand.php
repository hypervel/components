<?php

declare(strict_types=1);

namespace Hypervel\Server\Commands;

use Hypervel\Console\Command;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Events\Dispatcher as DispatcherContract;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Engine\Coroutine;
use Hypervel\Foundation\Application;
use Hypervel\Server\ServerFactory;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;

use function Hypervel\Support\swoole_hook_flags;

#[AsCommand(name: 'serve', description: 'Start Hypervel servers.')]
class ServerStartCommand extends Command
{
    public function __construct(private Container $container)
    {
        parent::__construct('serve');
        $this->setDescription('Start Hypervel servers.');
    }

    /**
     * Execute the server start command.
     */
    public function handle(): int
    {
        if (Application::getInstance()->runningInConsole()) {
            throw new RuntimeException(
                'Error: APP_RUNNING_IN_CONSOLE is true. Your artisan binary may be outdated. Please update it so the serve and watch commands set APP_RUNNING_IN_CONSOLE=false before the server starts.'
            );
        }

        $serverFactory = $this->container->make(ServerFactory::class)
            ->setEventDispatcher($this->container->make(DispatcherContract::class))
            ->setLogger($this->container->make(StdoutLoggerInterface::class));

        $serverConfig = $this->container->make(Repository::class)->get('server', []);
        if (! $serverConfig) {
            throw new InvalidArgumentException('At least one server should be defined.');
        }

        $serverFactory->configure($serverConfig);

        Coroutine::set(['hook_flags' => swoole_hook_flags()]);

        $serverFactory->start();

        return 0;
    }
}
