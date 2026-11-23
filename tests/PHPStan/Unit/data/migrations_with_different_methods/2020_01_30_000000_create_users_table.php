<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Unit\Migrations;

use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;

class CreateUsersTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->createUsersTable();
    }

    public function down(): void
    {
        Schema::drop('users');
    }

    private function createUsersTable(): void
    {
        Schema::create('users', static function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->timestamps();
        });
    }
}
