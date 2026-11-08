<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Unit\ConditionalMigrations;

use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;

class AddAddress2ToUsersTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('users') === true) {
            Schema::table('users', static function (Blueprint $table) {
                if (Schema::hasColumn('users', 'address2') === true) {
                    return;
                }

                $table->string('address2')->nullable();
            });
        }
    }
}
