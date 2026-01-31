<?php

declare(strict_types=1);

use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Use string(36) for UUID and string(26) for ULID for SQLite compatibility
        Schema::create('has_uuids_test_models', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('has_ulids_test_models', function (Blueprint $table) {
            $table->string('id', 26)->primary();
            $table->string('name');
            $table->timestamps();
        });
    }
};
