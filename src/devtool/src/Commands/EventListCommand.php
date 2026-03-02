<?php

declare(strict_types=1);

namespace Hypervel\Devtool\Commands;

use Closure;
use Hypervel\Console\Command;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Event\Dispatcher as DispatcherContract;
use Hypervel\Events\Dispatcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lists all registered events and their listeners.
 */
#[AsCommand(name: 'event:list')]
class EventListCommand extends Command
{
    public function __construct(private Container $container)
    {
        parent::__construct('event:list');
    }

    public function handle(): void
    {
        $eventFilter = $this->input->getOption('event');
        $listenerFilter = $this->input->getOption('listener');

        /** @var \Hypervel\Events\Dispatcher $dispatcher */
        $dispatcher = $this->container->make(DispatcherContract::class);

        $this->show($this->handleData($dispatcher, $eventFilter, $listenerFilter), $this->output);
    }

    protected function configure(): void
    {
        $this->setDescription("List the application's events and listeners.")
            ->addOption('event', 'e', InputOption::VALUE_OPTIONAL, 'Filter the events by event name.')
            ->addOption('listener', 'l', InputOption::VALUE_OPTIONAL, 'Filter the events by listener name.');
    }

    /**
     * Process raw listeners into display format.
     */
    protected function handleData(Dispatcher $dispatcher, ?string $eventFilter, ?string $listenerFilter): array
    {
        $data = [];

        foreach ($dispatcher->getRawListeners() as $event => $rawListeners) {
            if (! is_array($rawListeners)) {
                continue;
            }

            if ($eventFilter && ! str_contains($event, $eventFilter)) {
                continue;
            }

            $formattedListeners = [];

            foreach ($rawListeners as $listener) {
                $formatted = $this->formatListener($listener);

                if ($listenerFilter && ! str_contains($formatted, $listenerFilter)) {
                    continue;
                }

                $formattedListeners[] = $formatted;
            }

            if (! empty($formattedListeners)) {
                $data[$event] = [
                    'events' => $event,
                    'listeners' => $formattedListeners,
                ];
            }
        }

        return $data;
    }

    /**
     * Format a raw listener for display.
     */
    protected function formatListener(mixed $listener): string
    {
        if (is_string($listener)) {
            return $listener;
        }

        if ($listener instanceof Closure) {
            return 'Closure';
        }

        if (is_array($listener) && count($listener) === 2) {
            [$object, $method] = $listener;
            $className = is_string($object) ? $object : get_class($object);

            return $className . '::' . $method;
        }

        return 'Unknown listener';
    }

    protected function show(array $data, OutputInterface $output): void
    {
        if (empty($data)) {
            $output->writeln('<info>No events registered.</info>');

            return;
        }

        $rows = [];
        foreach ($data as $row) {
            $row['listeners'] = implode(PHP_EOL, (array) $row['listeners']);
            $rows[] = $row;
            $rows[] = new TableSeparator();
        }

        // Remove trailing separator
        array_pop($rows);

        $table = new Table($output);
        $table->setHeaders(['Events', 'Listeners'])->setRows($rows);
        $table->render();
    }
}
