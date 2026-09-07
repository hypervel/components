<?php

declare(strict_types=1);

use Hypervel\Context\CoroutineContext;
use Hypervel\Database\ConnectionResolver;
use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Create a new migration instance.
     */
    public function __construct()
    {
        $this->connection = CoroutineContext::get(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY) === 'default-direct'
            ? 'context-target'
            : 'wrong-target';
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('context_probe', function (Blueprint $table) {
            $table->id();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('context_probe');
    }
};
