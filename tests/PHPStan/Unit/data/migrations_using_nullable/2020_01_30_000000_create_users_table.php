<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Unit\MigrationsUsingNullable;

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
        Schema::create('users', static function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable(false);
            $table->string('email')->unique()->nullable();
            $table->string('address1')->nullable(true);
            $table->timestamps();
        });
    }
}
