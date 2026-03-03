<?php

declare(strict_types=1);

use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;

return new class extends Migration {
    public function shouldRun(): bool
    {
        return false;
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('skipped_table', function (Blueprint $table) {
            $table->id();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('skipped_table');
    }
};
