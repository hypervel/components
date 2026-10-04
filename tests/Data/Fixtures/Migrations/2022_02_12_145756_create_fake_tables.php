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
        Schema::create('fake_models', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->string('string');
            $table->string('nullable')->nullable();
            $table->string('date');

            $table->timestamps();
        });

        Schema::create('fake_nested_models', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->string('string');
            $table->string('nullable')->nullable();
            $table->string('date');

            $table->unsignedBigInteger('fake_model_id')->nullable();
            $table->foreign('fake_model_id')->references('id')->on('fake_models')->cascadeOnDelete();

            $table->timestamps();
        });
    }
};
