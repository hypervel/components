<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Unit\MigrationWithDefaultArguments;

use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;

class CreateUsersTable extends Migration
{
    public function up(): void
    {
        Schema::create('users', static function (Blueprint $table) {
            $table->id();
            $table->ipAddress();
            $table->ipAddress('custom_ip_address');
            $table->macAddress();
            $table->macAddress('custom_mac_address');
            $table->uuid();
            $table->uuid('custom_uuid');
            $table->ulid();
            $table->ulid('custom_ulid');
            $table->softDeletes();
            $table->softDeletes('custom_soft_deletes');
        });
    }
}
