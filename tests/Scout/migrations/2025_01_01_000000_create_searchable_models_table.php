<?php

declare(strict_types=1);

use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('searchable_models', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->timestamps();
        });

        Schema::create('soft_deletable_searchable_models', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('scout_through_owners', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });

        Schema::create('scout_through_intermediates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id');
            $table->timestamps();
        });

        Schema::create('scout_through_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intermediate_id');
            $table->string('title');
            $table->timestamps();
        });
    }
};
