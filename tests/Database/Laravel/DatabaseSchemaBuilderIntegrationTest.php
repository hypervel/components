<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Laravel;

use Hypervel\Database\Capsule\Manager as DB;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class DatabaseSchemaBuilderIntegrationTest extends TestCase
{
    protected DB $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new DB();

        $this->db->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $this->db->setAsGlobal();
    }

    public function testHasColumnWithTablePrefix()
    {
        $this->db->connection()->setTablePrefix('test_');

        $this->db->connection()->getSchemaBuilder()->create('table1', function (Blueprint $table) {
            $table->integer('id');
            $table->string('name');
        });

        $this->assertTrue($this->db->connection()->getSchemaBuilder()->hasColumn('table1', 'name'));
    }

    public function testHasColumnAndIndexWithPrefixIndexDisabled()
    {
        $this->db->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => 'example_',
            'prefix_indexes' => false,
        ]);

        $this->schemaBuilder()->create('table1', function (Blueprint $table) {
            $table->integer('id');
            $table->string('name')->index();
        });

        $this->assertTrue($this->schemaBuilder()->hasIndex('table1', 'table1_name_index'));
    }

    public function testHasColumnAndIndexWithPrefixIndexEnabled()
    {
        $this->db->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => 'example_',
            'prefix_indexes' => true,
        ]);

        $this->schemaBuilder()->create('table1', function (Blueprint $table) {
            $table->integer('id');
            $table->string('name')->index();
        });

        $this->assertTrue($this->schemaBuilder()->hasIndex('table1', 'example_table1_name_index'));
    }

    public function testDropColumnWithTablePrefix()
    {
        $this->db->connection()->setTablePrefix('test_');

        $this->schemaBuilder()->create('pandemic_table', function (Blueprint $table) {
            $table->integer('id');
            $table->string('stay_home');
            $table->string('covid19');
            $table->string('wear_mask');
        });

        // drop single columns
        $this->assertTrue($this->schemaBuilder()->hasColumn('pandemic_table', 'stay_home'));
        $this->schemaBuilder()->dropColumns('pandemic_table', 'stay_home');
        $this->assertFalse($this->schemaBuilder()->hasColumn('pandemic_table', 'stay_home'));

        // drop multiple columns
        $this->assertTrue($this->schemaBuilder()->hasColumn('pandemic_table', 'covid19'));
        $this->schemaBuilder()->dropColumns('pandemic_table', ['covid19', 'wear_mask']);
        $this->assertFalse($this->schemaBuilder()->hasColumn('pandemic_table', 'wear_mask'));
        $this->assertFalse($this->schemaBuilder()->hasColumn('pandemic_table', 'covid19'));
    }

    private function schemaBuilder(): \Hypervel\Database\Schema\Builder
    {
        return $this->db->connection()->getSchemaBuilder();
    }
}
