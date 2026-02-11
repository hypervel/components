<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use InvalidArgumentException;
use Hypervel\Contracts\Container\Container;
use Swoole\Table;

class SwooleTableManager
{
    protected array $tables = [];

    public function __construct(
        protected Container $app
    ) {
    }

    public function createTable(int $rows, int $bytes, float $conflictProportion): Table
    {
        $table = new SwooleTable($rows, $conflictProportion);

        $table->column('value', Table::TYPE_STRING, $bytes);
        $table->column('expiration', Table::TYPE_FLOAT);
        $table->column('last_used_at', Table::TYPE_FLOAT);
        $table->column('used_count', Table::TYPE_INT);

        $table->create();

        return $table;
    }

    public function get(string $name): Table
    {
        return $this->tables[$name] ??= $this->resolve($name);
    }

    protected function resolve(string $name): Table
    {
        $config = $this->getConfig($name);

        if (is_null($config)) {
            throw new InvalidArgumentException("Swoole table [{$name}] is not defined.");
        }

        return $this->createTable(
            $config['rows'] ?? 1024,
            $config['bytes'] ?? 10240,
            $config['conflict_proportion'] ?? 0.2
        );
    }

    protected function getConfig(string $name): ?array
    {
        if ($name !== 'null') {
            return $this->app->get('config')->get("cache.swoole_tables.{$name}");
        }

        return null;
    }
}
