<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Testing;

use Hypervel\Database\Schema\Blueprint;
use Hypervel\Foundation\Testing\DatabaseTruncation;
use Hypervel\Testbench\TestCase;

class DatabaseTruncationTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        static::$allTables = [];

        parent::tearDown();
    }

    public function testTruncationPreservesTheInitiallyDisabledTestbenchForeignKeyState(): void
    {
        $config = $this->app->make('config');
        $connection = $this->app->make('db')->connection();
        $schema = $connection->getSchemaBuilder();

        $this->assertFalse($config->boolean('database.connections.testing.foreign_key_constraints'));
        $this->assertSame(0, (int) $connection->scalar('pragma foreign_keys'));

        $schema->create('truncation_parents', function (Blueprint $table): void {
            $table->id();
        });
        $schema->create('truncation_children', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->constrained('truncation_parents');
        });
        $connection->table('truncation_parents')->insert(['id' => 1]);
        $connection->table('truncation_children')->insert(['id' => 1, 'parent_id' => 1]);

        $this->truncateTablesForAllConnections();

        $this->assertSame(0, $connection->table('truncation_parents')->count());
        $this->assertSame(0, $connection->table('truncation_children')->count());
        $this->assertSame(0, (int) $connection->scalar('pragma foreign_keys'));
    }
}
