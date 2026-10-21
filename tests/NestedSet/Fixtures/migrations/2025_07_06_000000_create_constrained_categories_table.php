<?php

declare(strict_types=1);

use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\NestedSet\NestedSet;
use Hypervel\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('constrained_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            NestedSet::columns($table);
            $table->foreign(NestedSet::PARENT_ID)->references('id')->on('constrained_categories');
        });

        Schema::create('constrained_category_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->constrained('constrained_categories');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('constrained_category_items');
        Schema::dropIfExists('constrained_categories');
    }
};
