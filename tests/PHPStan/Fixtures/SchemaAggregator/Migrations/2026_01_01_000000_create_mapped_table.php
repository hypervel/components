<?php

declare(strict_types=1);

use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\PHPStan\Fixtures\SchemaAggregator\SentinelMigration;

return new class extends SentinelMigration {
    /**
     * Create a table on the mapped connection.
     */
    public function up(): void
    {
        Schema::create('mapped', fn (Blueprint $table) => $table->string('name'));
    }
};
