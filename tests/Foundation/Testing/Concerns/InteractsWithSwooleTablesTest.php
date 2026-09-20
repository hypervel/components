<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing\Concerns;

use Hypervel\Foundation\Testing\Concerns\InteractsWithSwooleTables;
use Hypervel\Tests\TestCase;
use Swoole\Table;

class InteractsWithSwooleTablesTest extends TestCase
{
    use InteractsWithSwooleTables;

    public function testDestroysTrackedTablesOnce(): void
    {
        $table = new Table(64);
        $table->column('count', Table::TYPE_INT);
        $table->create();

        $this->trackSwooleTable($table);
        $this->destroySwooleTables();

        // PHPUnit runs the hook again after this test. A second destroy() on
        // the same table is fatal, so the list must already be empty.
        $this->assertSame([], $this->swooleTables);
    }

    public function testTracksTheSameTableOnce(): void
    {
        $table = new Table(64);
        $table->column('count', Table::TYPE_INT);
        $table->create();

        $this->trackSwooleTable($table);
        $this->trackSwooleTable($table);

        $this->assertCount(1, $this->swooleTables);
    }
}
