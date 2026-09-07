<?php

declare(strict_types=1);

use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Testbench\Databases\CombinedDatabaseResetMigrationCounter;

return new class extends Migration {
    protected ?string $connection = 'truncated';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        ++CombinedDatabaseResetMigrationCounter::$runs;

        Schema::create('truncated_records', function (Blueprint $table): void {
            $table->id();
            $table->string('value');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('truncated_records');
    }
};
