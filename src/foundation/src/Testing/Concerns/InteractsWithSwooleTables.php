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
     * @var list<Table>
     */
    protected array $swooleTables = [];

    /**
     * Track Swoole tables so their shared memory is released after the test.
     */
    protected function trackSwooleTable(Table ...$tables): void
    {
        $this->swooleTables = [...$this->swooleTables, ...$tables];
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
