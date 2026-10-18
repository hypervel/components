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
        Schema::create('oauth_pushed_authorization_requests', function (Blueprint $table): void {
            $table->char('id', 64)->primary();
            $table->foreignUuid('client_id');
            $table->jsonb('parameters');
            $table->jsonb('scopes');
            $table->text('redirect_uri');
            $table->dateTime('expires_at')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('oauth_pushed_authorization_requests');
    }

    /**
     * Get the migration connection name.
     */
    public function getConnection(): ?string
    {
        return $this->connection ?? config('passport.connection');
    }
};
