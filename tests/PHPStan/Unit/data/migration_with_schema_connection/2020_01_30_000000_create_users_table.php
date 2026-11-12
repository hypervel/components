<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Unit\MigrationWithSchemaConnection;

use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;

class CreateUsersTable extends Migration
{
    public function up(): void
    {
        Schema::connection('mysql')->create('users', static function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->timestamps();
        });
    }
}
