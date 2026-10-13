<?php

declare(strict_types=1);

use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('oauth_signing_keys', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->text('public_key');
            $table->text('private_key')->nullable();
            $table->dateTime('activates_at');
            $table->unsignedInteger('max_token_lifetime')->default(0);
            $table->dateTime('retain_until')->nullable();
            $table->dateTime('retires_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('oauth_signing_keys');
    }

    /**
     * Get the migration connection name.
     */
    public function getConnection(): ?string
    {
        return $this->connection ?? config('passport.connection');
    }
};
