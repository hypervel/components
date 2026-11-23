<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Unit\MigrationsUsingAfterMethod;

use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;

class AlterUsersTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', static function (Blueprint $table) {
            $table->after('name', function (Blueprint $table) {
                $table->string('email')->unique();
            });
        });
    }
}
