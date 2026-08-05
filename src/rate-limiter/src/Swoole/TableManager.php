<?php

declare(strict_types=1);

namespace Hypervel\RateLimiter\Swoole;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Core\Swoole\StripedLock;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Swoole\Table;

class TableManager
{
    /**
     * The tables created before the server forks.
     *
     * @var array<string, TableState>
     */
    protected array $states = [];

    protected bool $sealed = false;

    /**
     * Create a new Swoole table manager.
     */
    public function __construct(protected Repository $config)
    {
    }

    /**
     * Get a Swoole rate limiter table by store name.
     */
    public function get(string $name): TableState
    {
        if (isset($this->states[$name])) {
            return $this->states[$name];
        }

        if ($this->sealed) {
            throw new LogicException(
                "Swoole rate limiter table [{$name}] was not initialized before the server fork."
            );
        }

        return $this->states[$name] = $this->resolve($name);
    }

    /**
     * Prevent tables from being created after the server initialization phase.
     *
     * Boot-only. Creating a table after the server forks would give each worker
     * private state instead of one shared rate limiter.
     */
    public function seal(): void
    {
        $this->sealed = true;
    }

    /**
     * Resolve a configured Swoole rate limiter table.
     */
    protected function resolve(string $name): TableState
    {
        $config = $this->config->get("rate-limiter.stores.{$name}");

        if (! is_array($config) || ($config['driver'] ?? null) !== 'swoole') {
            throw new InvalidArgumentException("Swoole rate limiter store [{$name}] is not defined.");
        }

        $rows = $this->config->integer("rate-limiter.stores.{$name}.rows");
        $conflictProportion = $this->config->float("rate-limiter.stores.{$name}.conflict_proportion");

        if ($rows <= 0) {
            throw new InvalidArgumentException('The Swoole rate limiter row count must be a positive integer.');
        }

        if ($conflictProportion < 0.2 || $conflictProportion > 1.0) {
            throw new InvalidArgumentException(
                'The Swoole rate limiter conflict proportion must be between 0.2 and 1.0, inclusive.'
            );
        }

        $table = new Table($rows, $conflictProportion);
        $table->column('value', Table::TYPE_INT, 8);
        $table->column('available_at', Table::TYPE_INT, 8);
        $table->column('expires_at', Table::TYPE_INT, 8);

        if (! $table->create()) {
            throw new RuntimeException("Unable to create Swoole rate limiter table [{$name}].");
        }

        return new TableState($name, $table, new StripedLock);
    }
}
