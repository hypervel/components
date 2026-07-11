<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Hypervel\Contracts\Container\Container;
use InvalidArgumentException;
use Swoole\Table;

class SwooleTableManager
{
    /**
     * The resolved Swoole table states.
     *
     * @var array<string, SwooleTableState>
     */
    protected array $states = [];

    public function __construct(
        protected Container $app
    ) {
    }

    /**
     * Create a Swoole table state.
     */
    public function createState(int $rows, int $bytes, float $conflictProportion, int $hashSeed = 0): SwooleTableState
    {
        return new SwooleTableState(
            $this->createTable($rows, $bytes, $conflictProportion),
            $hashSeed
        );
    }

    /**
     * Create a Swoole table.
     */
    public function createTable(int $rows, int $bytes, float $conflictProportion): SwooleTable
    {
        $table = new SwooleTable($rows, $conflictProportion);

        $table->column('value', Table::TYPE_STRING, $bytes);
        $table->column('expiration', Table::TYPE_FLOAT);
        $table->column('last_used_at', Table::TYPE_FLOAT);
        $table->column('used_count', Table::TYPE_INT);

        $table->create();

        return $table;
    }

    /**
     * Get a Swoole table state by name.
     */
    public function get(string $name): SwooleTableState
    {
        return $this->states[$name] ??= $this->resolve($name);
    }

    /**
     * Resolve a Swoole table state by name.
     */
    protected function resolve(string $name): SwooleTableState
    {
        $config = $this->getConfig($name);

        if (is_null($config)) {
            throw new InvalidArgumentException("Swoole table [{$name}] is not defined.");
        }

        return $this->createState(
            $config['rows'] ?? 1024,
            $config['bytes'] ?? 10240,
            $config['conflict_proportion'] ?? 0.2
        );
    }

    /**
     * Get the Swoole table configuration.
     */
    protected function getConfig(string $name): ?array
    {
        if ($name !== 'null') {
            return $this->app->make('config')->get("cache.swoole_tables.{$name}");
        }

        return null;
    }
}
