<?php

declare(strict_types=1);

use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cast_models', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('age')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->boolean('is_active')->default(false);
            $table->jsonb('metadata')->nullable();
            $table->jsonb('settings')->nullable();
            $table->jsonb('tags')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->date('birth_date')->nullable();
            $table->text('content')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });
    }
};
