<?php

declare(strict_types=1);

namespace Database\Migrations;

use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;

class CreateUuidModelsTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('uuid_models', static function (Blueprint $table) {
            $table->uuid('id');
            $table->timestamps();
        });
    }
}
