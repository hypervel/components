<?php

declare(strict_types=1);

namespace Hypervel\Watcher\Console;

use Hypervel\Console\Command;
use Hypervel\Console\Concerns\NullDisableEventDispatcher;
use Hypervel\Contracts\Container\Container;
use Hypervel\Watcher\Option;
use Hypervel\Watcher\Watcher;
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
        $this->addOption('file', 'F', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Additional files to watch', []);
        $this->addOption('dir', 'D', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Additional directories to watch', []);
        $this->addOption('no-restart', 'N', InputOption::VALUE_NONE, 'Detect changes without restarting the server');
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $options = $this->container->make('config')->get('watcher', []);

        $option = $this->container->make(Option::class, [
            'options' => $options,
            'dir' => $this->input->getOption('dir'),
            'file' => $this->input->getOption('file'),
            'restart' => ! $this->input->getOption('no-restart'),
        ]);

        $watcher = $this->container->make(Watcher::class, [
            'option' => $option,
            'output' => $this->output,
        ]);

        $watcher->run();
    }
}
