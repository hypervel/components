<?php

declare(strict_types=1);

namespace Database\Migrations;

use App\Address;
use App\User;
use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;

class CreateAddressesTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('addresses', static function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid();
            $table->foreignIdFor(User::class);
            $table->foreignIdFor(User::class, 'custom_foreign_id_for_name');
            $table->foreignIdFor(Address::class);
            $table->foreignIdFor(Address::class, 'nullable_address_id')->nullable();
            $table->foreignId('foreign_id_constrained')->constrained('users');
            $table->foreignId('nullable_foreign_id_constrained')->nullable()->constrained('users');
            // Unlike upstream, these are not constrained to addresses.id, an integer key that databases reject as their reference.
            $table->foreignUlid('foreign_ulid');
            $table->foreignUlid('nullable_foreign_ulid')->nullable();
            $table->foreignUuid('foreign_uuid');
            $table->foreignUuid('nullable_foreign_uuid')->nullable();
        });
    }
}
