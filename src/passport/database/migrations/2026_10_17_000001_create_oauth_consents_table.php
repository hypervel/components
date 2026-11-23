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
        Schema::create('oauth_consents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id');
            $table->string('provider');
            $table->foreignId('user_id');
            $table->jsonb('scopes')->nullable();
            $table->jsonb('permissions');
            $table->dateTime('remembered_until')->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'provider', 'user_id']);
            $table->index(['provider', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('oauth_consents');
    }

    /**
     * Get the migration connection name.
     */
    public function getConnection(): ?string
    {
        return $this->connection ?? config('passport.connection');
    }
};
