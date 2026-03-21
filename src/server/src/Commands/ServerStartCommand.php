<?php

declare(strict_types=1);

namespace Hypervel\Server\Commands;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Events\Dispatcher as DispatcherContract;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Engine\Coroutine;
use Hypervel\Foundation\Application;
use Hypervel\Server\ServerFactory;
use InvalidArgumentException;
use Override;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function Hypervel\Support\swoole_hook_flags;

/**
 * Extends Symfony Command directly — NOT Hypervel\Console\Command — because the
 * Swoole server must own the event loop. Hypervel\Console\Command brings coroutine
 * wrapping and signal traits that start the event loop before Server::start().
 */
#[AsCommand(name: 'serve', description: 'Start Hypervel servers.')]
class ServerStartCommand extends SymfonyCommand
{
    public function __construct(protected Container $container)
    {
        parent::__construct('serve');
        $this->setDescription('Start Hypervel servers.');
    }

    /**
     * Execute the server start command.
     */
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->startServer();
    }

    /**
     * Start the configured Swoole servers.
     */
    protected function startServer(): int
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
