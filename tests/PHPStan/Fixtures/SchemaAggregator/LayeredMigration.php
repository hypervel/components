<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\SchemaAggregator;

use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;

abstract class LayeredMigration extends Migration
{
    use CreatesParts;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        self::createLayer();
        static::createLayer();
        $this->createParts();
        $this->createLedgers();
    }

    /**
     * Create the layer table this class names.
     */
    protected function createLayer(): void
    {
        Schema::create('base_layers', fn (Blueprint $table) => $table->string('name'));
    }

    /**
     * Create the ledgers table, which up() runs whatever private method of that name a migration declares.
     */
    private function createLedgers(): void
    {
        Schema::create('ledgers', fn (Blueprint $table) => $table->string('name'));
    }

    /**
     * Create the widgets table.
     */
    protected function createWidgets(): void
    {
        Schema::create('widgets', fn (Blueprint $table) => $table->string('name'));
    }

    /**
     * Create the gadgets table, then call itself once more.
     */
    protected function createGadgets(bool $again = false): void
    {
        if ($again) {
            return;
        }

        Schema::create('gadgets', fn (Blueprint $table) => $table->string('name'));

        $this->createGadgets(true);
    }

    /**
     * Create the gizmos table on the migration's connection through a stored builder.
     */
    protected function createGizmos(): void
    {
        $schema = Schema::connection($this->connection);

        $schema->create('gizmos', fn (Blueprint $table) => $table->string('name'));
    }
}
