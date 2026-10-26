<?php

declare(strict_types=1);

use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;

return new class extends Migration {
    protected ?string $connection = 'secondary';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('secondary_records', function (Blueprint $table): void {
            $table->id();
            $table->string('value');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('secondary_records');
    }
};
