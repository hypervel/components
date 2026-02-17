<?php

declare(strict_types=1);

namespace Hypervel\Server\Command;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Engine\Coroutine;
use Hypervel\Server\ServerFactory;
use Hypervel\Support\Composer;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function Hypervel\Support\swoole_hook_flags;

class StartServer extends Command
{
    public function __construct(private ContainerInterface $container)
    {
        parent::__construct('start');
        $this->setDescription('Start hypervel servers.');
    }

    /**
     * Execute the server start command.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->checkEnvironment($output);

        $serverFactory = $this->container->make(ServerFactory::class)
            ->setEventDispatcher($this->container->make(EventDispatcherInterface::class))
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

    /**
     * Check if the Swoole environment is properly configured.
     */
    private function checkEnvironment(OutputInterface $output): void
    {
        if (! extension_loaded('swoole') || ! Composer::hasPackage('hyperf/polyfill-coroutine')) {
            return;
        }
        /**
         * swoole.use_shortname = true       => string(1) "1"     => enabled
         * swoole.use_shortname = "true"     => string(1) "1"     => enabled
         * swoole.use_shortname = on         => string(1) "1"     => enabled
         * swoole.use_shortname = On         => string(1) "1"     => enabled
         * swoole.use_shortname = "On"       => string(2) "On"    => enabled
         * swoole.use_shortname = "on"       => string(2) "on"    => enabled
         * swoole.use_shortname = 1          => string(1) "1"     => enabled
         * swoole.use_shortname = "1"        => string(1) "1"     => enabled
         * swoole.use_shortname = 2          => string(1) "1"     => enabled
         * swoole.use_shortname = false      => string(0) ""      => disabled
         * swoole.use_shortname = "false"    => string(5) "false" => disabled
         * swoole.use_shortname = off        => string(0) ""      => disabled
         * swoole.use_shortname = Off        => string(0) ""      => disabled
         * swoole.use_shortname = "off"      => string(3) "off"   => disabled
         * swoole.use_shortname = "Off"      => string(3) "Off"   => disabled
         * swoole.use_shortname = 0          => string(1) "0"     => disabled
         * swoole.use_shortname = "0"        => string(1) "0"     => disabled
         * swoole.use_shortname = 00         => string(2) "00"    => disabled
         * swoole.use_shortname = "00"       => string(2) "00"    => disabled
         * swoole.use_shortname = ""         => string(0) ""      => disabled
         * swoole.use_shortname = " "        => string(1) " "     => disabled.
         */
        $useShortname = ini_get_all('swoole')['swoole.use_shortname']['local_value'];
        $useShortname = strtolower(trim(str_replace('0', '', $useShortname)));
        if (! in_array($useShortname, ['', 'off', 'false'], true)) {
            $output->writeln("<error>ERROR</error> Swoole short function names must be disabled before the server starts, please set swoole.use_shortname='Off' in your php.ini.");
            exit(SIGTERM);
        }
    }
}
