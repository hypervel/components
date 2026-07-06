<?php

declare(strict_types=1);

namespace Hypervel\Server\Commands;

use Hypervel\Contracts\Config\Repository as ConfigRepository;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Engine\Coroutine;
use Hypervel\Foundation\Application;
use Hypervel\Server\ServerFactory;
use Hypervel\Server\ServerInterface;
use InvalidArgumentException;
use Override;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
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
        return $this->startServer($input);
    }

    /**
     * Configure the server start command.
     */
    #[Override]
    protected function configure(): void
    {
        $this
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'The host address to serve the application on')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'The port to serve the application on');
    }

    /**
     * Start the configured Swoole servers.
     */
    protected function startServer(InputInterface $input): int
    {
        if (Application::getInstance()->runningInConsole()) {
            throw new RuntimeException(
                'Error: APP_RUNNING_IN_CONSOLE is true. Your artisan binary may be outdated. Please update it so the serve and watch commands set APP_RUNNING_IN_CONSOLE=false before the server starts.'
            );
        }

        $serverFactory = $this->container->make(ServerFactory::class)
            ->setEventDispatcher($this->container->make('events'))
            ->setLogger($this->container->make(StdoutLoggerInterface::class));

        /** @var ConfigRepository $config */
        $config = $this->container->make('config');
        $serverConfig = $config->array('server', []);
        if (! $serverConfig) {
            throw new InvalidArgumentException('At least one server should be defined.');
        }

        $host = $input->getOption('host');
        $port = $input->getOption('port');

        if ($host !== null || $port !== null) {
            if ($port !== null && filter_var($port, FILTER_VALIDATE_INT) === false) {
                throw new InvalidArgumentException('The serve port must be an integer.');
            }

            $servers = $serverConfig['servers'] ?? [];
            $httpServerIndex = null;

            foreach ($servers as $index => $server) {
                if (($server['type'] ?? null) === ServerInterface::SERVER_HTTP) {
                    $httpServerIndex = $index;
                    break;
                }
            }

            if ($httpServerIndex === null) {
                throw new InvalidArgumentException('Cannot override server host or port because no HTTP server is configured.');
            }

            if ($host !== null) {
                $servers[$httpServerIndex]['host'] = (string) $host;
            }

            if ($port !== null) {
                $servers[$httpServerIndex]['port'] = (int) $port;
            }

            $serverConfig['servers'] = $servers;

            // Command options are applied before workers start so ServerFactory
            // and later config readers agree on the bound HTTP address.
            $config->set('server.servers', $servers);
        }

        $serverFactory->configure($serverConfig);

        Coroutine::set(['hook_flags' => swoole_hook_flags()]);

        $serverFactory->start();

        return 0;
    }
}
