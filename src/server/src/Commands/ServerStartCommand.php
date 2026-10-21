<?php

declare(strict_types=1);

namespace Hypervel\Server\Commands;

use Hypervel\Contracts\Config\Repository as ConfigRepository;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Engine\Coroutine;
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
    // Laravel's ServeCommand subprocess and output-parser helpers are omitted;
    // ServerFactory starts Swoole directly.

    public function __construct(protected Application $application)
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
        if ($this->application->runningInConsole()) {
            throw new RuntimeException(
                'Error: APP_RUNNING_IN_CONSOLE is true. Your artisan binary may be outdated. Please update it so the serve and watch commands set APP_RUNNING_IN_CONSOLE=false before the server starts.'
            );
        }

        $serverFactory = $this->application->make(ServerFactory::class)
            ->setEventDispatcher($this->application->make('events'))
            ->setLogger($this->application->make(StdoutLoggerInterface::class));

        /** @var ConfigRepository $config */
        $config = $this->application->make('config');
        $serverConfig = $config->array('server');
        if (! $serverConfig) {
            throw new InvalidArgumentException('At least one server should be defined.');
        }

        $host = $input->getOption('host');
        $port = $input->getOption('port');

        if ($host !== null) {
            [$host, $hostPort] = $this->getHostAndPort((string) $host);
            $port ??= $hostPort;
        }

        if ($host !== null || $port !== null) {
            if ($port !== null && filter_var($port, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => 65535],
            ]) === false) {
                throw new InvalidArgumentException('The serve port must be an integer between 1 and 65535.');
            }

            $servers = $serverConfig['servers'];
            $httpServerIndex = null;

            foreach ($servers as $index => $server) {
                if (($server['type'] ?? ServerInterface::SERVER_HTTP) === ServerInterface::SERVER_HTTP) {
                    $httpServerIndex = $index;
                    break;
                }
            }

            if ($httpServerIndex === null) {
                throw new InvalidArgumentException('Cannot override server host or port because no HTTP server is configured.');
            }

            if ($host !== null) {
                $servers[$httpServerIndex]['host'] = $host;

                if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
                    $socketType = $servers[$httpServerIndex]['sock_type'] ?? SWOOLE_SOCK_TCP;
                    $sslFlag = defined('SWOOLE_SSL') ? $socketType & SWOOLE_SSL : 0;
                    $transportType = $socketType & ~$sslFlag;

                    // Swoole selects the address family from the socket type, not the host.
                    if (in_array($transportType, [SWOOLE_SOCK_TCP, SWOOLE_SOCK_TCP6], true)) {
                        $addressType = str_contains($host, ':') ? SWOOLE_SOCK_TCP6 : SWOOLE_SOCK_TCP;

                        if ($transportType !== $addressType) {
                            $servers[$httpServerIndex]['sock_type'] = $addressType | $sslFlag;
                        }
                    }
                }
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

        return self::SUCCESS;
    }

    /**
     * Get the host and optional port from the host option.
     *
     * @return array{string, null|string}
     */
    protected function getHostAndPort(string $host): array
    {
        if (preg_match('/^\[([^\]]+)\](?::(.*))?$/D', $host, $matches) === 1) {
            // IPv6 brackets delimit the option's port but are not part of Swoole's address.
            return [$matches[1], $matches[2] ?? null];
        }

        if (substr_count($host, ':') === 1) {
            return explode(':', $host, 2);
        }

        return [$host, null];
    }
}
