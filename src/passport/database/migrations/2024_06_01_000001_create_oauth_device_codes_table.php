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
        Schema::create('oauth_device_codes', function (Blueprint $table): void {
            $table->char('id', 80)->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('provider')->nullable();
            $table->foreignUuid('client_id')->index();
            $table->char('user_code', 8)->unique();
            $table->jsonb('scopes');
            $table->boolean('revoked');
            $table->dateTime('user_approved_at')->nullable();
            $table->dateTime('last_polled_at')->nullable();
            $table->dateTime('expires_at')->nullable();

            // Expiry pruning only matches non-null dates.
            $table->index('expires_at')->whereNotNull('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('oauth_device_codes');
    }

    /**
     * Get the migration connection name.
     */
    public function getConnection(): ?string
    {
        return $this->connection ?? config('passport.connection');
    }
};
