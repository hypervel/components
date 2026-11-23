<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Unit\FetchOptionsMigrations;

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
        Schema::create('users', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->timestamp('created_at');
            $table->addColumn($this->payloadType(), 'payload');
            $table->addColumn($this->payloadType(), 'extra')->nullable();
        });
    }

    /**
     * Get a column type the schema reader cannot resolve.
     */
    private function payloadType(): string
    {
        return 'json';
    }
}
