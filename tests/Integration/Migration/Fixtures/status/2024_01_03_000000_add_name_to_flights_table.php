<?php

declare(strict_types=1);

use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Determine whether the migration should run.
     */
    public function shouldRun(): bool
    {
        return Schema::hasTable('flights');
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('flights', function (Blueprint $table): void {
            $table->string('name')->nullable();
        });
    }
};
