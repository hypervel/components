<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Unit\MigrationsUsingDropColumn;

use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;

class CreateAddDropToUsersTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', static function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('country')->nullable();
            $table->string('city')->nullable();
            $table->timestamps();
        });

        Schema::table('users', static function (Blueprint $table) {
            $table->dropColumn(['country', 'city']);
        });
    }
}
