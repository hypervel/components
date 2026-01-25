<?php

declare(strict_types=1);

use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tx_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('balance', 10, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('tx_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_account_id')->constrained('tx_accounts');
            $table->foreignId('to_account_id')->constrained('tx_accounts');
            $table->decimal('amount', 10, 2);
            $table->timestamps();
        });
    }
};
