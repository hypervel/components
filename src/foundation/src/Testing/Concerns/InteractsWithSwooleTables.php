<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

use PHPUnit\Framework\Attributes\After;
use Swoole\Table;

trait InteractsWithSwooleTables
{
    /**
     * The Swoole tables created by the test.
     *
     * Keyed by object ID so a table tracked twice is only destroyed once.
     *
     * @var array<int, Table>
     */
    protected array $swooleTables = [];

    /**
     * Track Swoole tables so their shared memory is released after the test.
     */
    protected function trackSwooleTable(Table ...$tables): void
    {
        foreach ($tables as $table) {
            $this->swooleTables[spl_object_id($table)] = $table;
        }
    }

    /**
     * Destroy the tracked Swoole tables.
     */
    #[After]
    protected function destroySwooleTables(): void
    {
        foreach ($this->swooleTables as $table) {
            $table->destroy();
        }

        $this->swooleTables = [];
    }
}
