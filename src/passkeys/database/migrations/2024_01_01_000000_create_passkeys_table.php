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
        Schema::create('passkeys', function (Blueprint $table): void {
            $table->id();
            $table->morphs('user');
            $table->string('name');
            // CTAP2 permits 1,023 raw bytes, requiring 1,364 unpadded Base64URL characters.
            // The binary charset keeps MySQL-family uniqueness case-sensitive like PostgreSQL and SQLite.
            $table->string('credential_id', 1364)->charset('binary')->unique();
            $table->jsonb('credential');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('passkeys');
    }
};
