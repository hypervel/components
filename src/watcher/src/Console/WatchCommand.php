<?php

declare(strict_types=1);

namespace Hypervel\Watcher\Console;

use Hypervel\Console\Command;
use Hypervel\Console\Concerns\NullDisableEventDispatcher;
use Hypervel\Contracts\Container\Container;
use Hypervel\Foundation\Application;
use Hypervel\Watcher\Option;
use Hypervel\Watcher\ServerRestartStrategy;
use Hypervel\Watcher\Watcher;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'watch')]
class WatchCommand extends Command
{
    use NullDisableEventDispatcher;

    public function __construct(protected Container $container)
    {
        parent::__construct('watch');
        $this->setDescription('Watch for file changes and automatically restart the server.');
        $this->addOption('path', 'P', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Additional paths to watch', []);
        $this->addOption('no-restart', 'N', InputOption::VALUE_NONE, 'Detect changes without restarting the server');
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        if (Application::getInstance()->runningInConsole()) {
            throw new RuntimeException(
                'Error: APP_RUNNING_IN_CONSOLE is true. Your artisan binary may be outdated. Please update it so the serve and watch commands set APP_RUNNING_IN_CONSOLE=false before the server starts.'
            );
        }

        $config = $this->container->make('config')->get('watcher', []);

        $option = Option::fromConfig(
            config: $config,
            basePath: base_path(),
            extraPaths: $this->input->getOption('path'),
        );

        $driver = $this->container->make($option->getDriver(), ['option' => $option]);

        $strategy = null;
        if (! $this->input->getOption('no-restart')) {
            $strategy = $this->container->make(ServerRestartStrategy::class, [
                'output' => $this->output,
            ]);
        }

        $watcher = $this->container->make(Watcher::class, [
            'driver' => $driver,
            'output' => $this->output,
            'strategy' => $strategy,
        ]);

        $watcher->run();
    }
}
