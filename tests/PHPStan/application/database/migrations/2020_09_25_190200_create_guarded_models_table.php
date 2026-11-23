<?php

declare(strict_types=1);

namespace Database\Migrations;

use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;

class CreateGuardedModelsTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('guarded_models', static function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('text');
            $table->timestamps();
        });
    }
}
