<?php

declare(strict_types=1);

use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;

return new class extends Migration {
    /**
     * The database connection that should be used by the migration.
     */
    protected ?string $connection = 'reporting-pooled';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('reporting_probe', function (Blueprint $table) {
            $table->id();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reporting_probe');
    }
};
