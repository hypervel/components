<?php

declare(strict_types=1);

use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('dummy_model_with_casts', function (Blueprint $table): void {
            $table->increments('id');

            $table->text('data')->nullable();
            $table->text('lazy_data')->nullable();
            $table->text('data_collection')->nullable();
            $table->text('lazy_data_collection')->nullable();
            $table->text('abstract_data')->nullable();
            $table->text('abstract_collection')->nullable();
        });

        Schema::create('dummy_model_with_jsons', function (Blueprint $table): void {
            $table->increments('id');

            $table->jsonb('data')->nullable();
            $table->jsonb('data_collection')->nullable();
        });
    }
};
