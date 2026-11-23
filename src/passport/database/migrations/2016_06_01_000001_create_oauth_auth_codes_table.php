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
        Schema::create('oauth_auth_codes', function (Blueprint $table): void {
            $table->char('id', 80)->primary();
            $table->foreignId('user_id')->index();
            $table->string('provider')->nullable();
            $table->foreignUuid('client_id');
            $table->uuid('consent_id')->nullable()->index();
            $table->char('access_token_id', 80)->nullable();
            $table->uuid('family_id')->nullable();
            $table->jsonb('scopes')->nullable();
            $table->jsonb('resources')->nullable();
            $table->char('dpop_jkt', 43)->nullable();
            $table->boolean('revoked');
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
        Schema::dropIfExists('oauth_auth_codes');
    }

    /**
     * Get the migration connection name.
     */
    public function getConnection(): ?string
    {
        return $this->connection ?? config('passport.connection');
    }
};
