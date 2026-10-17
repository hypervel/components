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
        Schema::create('oauth_clients', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->nullableMorphs('owner');
            $table->string('name');
            $table->string('secret')->nullable();
            $table->string('provider')->nullable();
            $table->jsonb('redirect_uris');
            $table->jsonb('grant_types');
            $table->jsonb('scopes')->nullable();
            $table->string('token_endpoint_auth_method')->nullable();
            $table->string('token_endpoint_auth_signing_alg')->nullable();
            $table->jsonb('jwks')->nullable();
            $table->text('jwks_uri')->nullable();
            $table->boolean('dpop_bound_access_tokens')->default(false);
            $table->boolean('require_pushed_authorization_requests')->default(false);
            $table->string('application_type')->default('web');
            $table->boolean('dynamically_registered')->default(false);
            $table->boolean('revoked');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('oauth_clients');
    }

    /**
     * Get the migration connection name.
     */
    public function getConnection(): ?string
    {
        return $this->connection ?? config('passport.connection');
    }
};
