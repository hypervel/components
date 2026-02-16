<?php

declare(strict_types=1);

namespace Hypervel\Console\Traits;

use Hypervel\Container\Container;
use Hypervel\Contracts\Event\Dispatcher;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

trait DisableEventDispatcher
{
    /**
     * Add the disable event dispatcher option to the command.
     */
    public function addDisableDispatcherOption(): void
    {
        $this->addOption('disable-event-dispatcher', null, InputOption::VALUE_NONE, 'Whether disable event dispatcher.');
    }

    /**
     * Disable or enable the event dispatcher based on the input option.
     */
    public function disableDispatcher(InputInterface $input): void
    {
        if (! $input->getOption('disable-event-dispatcher')) {
            $container = Container::getInstance();

            if (! $container->has(Dispatcher::class)) {
                return;
            }

            $dispatcher = $container->make(Dispatcher::class);

            $this->eventDispatcher = $dispatcher instanceof Dispatcher ? $dispatcher : null;
        }
    }
}
