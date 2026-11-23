<?php

declare(strict_types=1);

namespace Database\Migrations;

use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;

class CreateUlidModelsTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ulid_models', static function (Blueprint $table) {
            $table->ulid('id');
            $table->timestamps();
        });
    }
}
