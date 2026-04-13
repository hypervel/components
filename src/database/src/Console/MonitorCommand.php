<?php

declare(strict_types=1);

namespace Hypervel\Database\Console;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Events\DatabaseBusy;
use Hypervel\Support\Collection;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'db:monitor')]
class MonitorCommand extends DatabaseInspectionCommand
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'db:monitor
                {--databases= : The database connections to monitor}
                {--max= : The maximum number of connections that can be open before an event is dispatched}';

    /**
     * The console command description.
     */
    protected string $description = 'Monitor the number of connections on the specified database';

    /**
     * The connection resolver instance.
     */
    protected ConnectionResolverInterface $connection;

    /**
     * The events dispatcher instance.
     */
    protected Dispatcher $events;

    /**
     * Create a new command instance.
     */
    public function __construct(ConnectionResolverInterface $connection, Dispatcher $events)
    {
        parent::__construct();

        $this->connection = $connection;
        $this->events = $events;
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $databases = $this->parseDatabases($this->option('databases'));

        $this->displayConnections($databases);

        if ($this->option('max')) {
            $this->dispatchEvents($databases);
        }
    }

    /**
     * Parse the database into an array of the connections.
     */
    protected function parseDatabases(?string $databases): Collection
    {
        return (new Collection(explode(',', $databases ?? '')))->map(function ($database) {
            if (! $database) {
                $database = $this->hypervel->make('config')->get('database.default');
            }

            $maxConnections = $this->option('max');

            $connections = $this->connection->connection($database)->threadCount();

            return [
                'database' => $database,
                'connections' => $connections,
                'status' => $maxConnections && $connections >= $maxConnections ? '<fg=yellow;options=bold>ALERT</>' : '<fg=green;options=bold>OK</>',
            ];
        });
    }

    /**
     * Display the databases and their connection counts in the console.
     */
    protected function displayConnections(Collection $databases): void
    {
        $this->newLine();

        $this->components->twoColumnDetail('<fg=gray>Database name</>', '<fg=gray>Connections</>');

        $databases->each(function ($database) {
            $status = '[' . $database['connections'] . '] ' . $database['status'];

            $this->components->twoColumnDetail($database['database'], $status);
        });

        $this->newLine();
    }

    /**
     * Dispatch the database monitoring events.
     */
    protected function dispatchEvents(Collection $databases): void
    {
        $databases->each(function ($database) {
            if ($database['status'] === '<fg=green;options=bold>OK</>') {
                return;
            }

            $this->events->dispatch(
                new DatabaseBusy(
                    $database['database'],
                    $database['connections']
                )
            );
        });
    }
}
