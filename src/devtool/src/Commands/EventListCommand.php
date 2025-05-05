<?php

declare(strict_types=1);

namespace Hypervel\Devtool\Commands;

use Closure;
use Hyperf\Command\Command as HyperfCommand;
use Hypervel\Event\Contracts\Dispatcher as EventDispatcherContract;
use Hypervel\Event\ListenerData;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EventListCommand extends HyperfCommand
{
    public function __construct(private ContainerInterface $container)
    {
        parent::__construct('event:list');
    }

    public function handle()
    {
        $event = $this->input->getOption('event');
        $listener = $this->input->getOption('listener');

        /** @var EventDispatcherContract $dispatcher */
        $dispatcher = $this->container->get(EventDispatcherInterface::class);
        $this->show($this->handleData($dispatcher, $event, $listener), $this->output);
    }

    protected function configure()
    {
        $this->setDescription("List the application's events and listeners.")
            ->addOption('event', 'e', InputOption::VALUE_OPTIONAL, 'Filter the events by event name.')
            ->addOption('listener', 'l', InputOption::VALUE_OPTIONAL, 'Filter the events by listener name.');
    }

    protected function handleData(EventDispatcherInterface $dispatcher, ?string $filterEvent, ?string $filterListener): array
    {
        $data = [];
        if (! $dispatcher instanceof EventDispatcherContract) {
            return $data;
        }

        foreach ($dispatcher->getRawListeners() as $event => $listeners) {
            if (! is_array($listeners)) {
                continue;
            }

            if ($filterEvent && ! str_contains($event, $filterEvent)) {
                continue;
            }

            $listeners = array_filter($listeners, function ($listener) use ($filterListener) {
                if (! $listener instanceof ListenerData) {
                    return false;
                }

                return ! $filterListener || str_contains($listener->listener, $filterListener);
            });

            $listeners = array_map(function ($listener) {
                $listener = $listener->listener;
                if (is_array($listener) && count($listener) === 2) {
                    [$object, $method] = $listener;
                    $listenerClassName = is_string($object)
                        ? $object
                        : get_class($object);

                    return implode('::', [$listenerClassName, $method]);
                }

                if (is_string($listener)) {
                    return $listener;
                }

                if ($listener instanceof Closure) {
                    return 'Closure';
                }

                return 'Unknown listener';
            }, $listeners);

            $data[$event]['events'] = $event;
            $data[$event]['listeners'] = array_merge($data[$event]['listeners'] ?? [], $listeners);
        }

        return $data;
    }

    protected function show(array $data, OutputInterface $output): void
    {
        $rows = [];
        foreach ($data as $route) {
            $route['listeners'] = implode(PHP_EOL, (array) $route['listeners']);
            $rows[] = $route;
            $rows[] = new TableSeparator();
        }
        $rows = array_slice($rows, 0, count($rows) - 1);
        $table = new Table($output);
        $table->setHeaders(['Events', 'Listeners'])->setRows($rows);
        $table->render();
    }
}
