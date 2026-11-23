<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Unit\AdditionalMigrations;

use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;

class CreateTeamsTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('teams', static function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('team')->nullable();
            $table->string('owner_email')->unique();
            $table->timestamps();
        });
    }
}
