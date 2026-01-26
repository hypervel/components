<?php

declare(strict_types=1);

use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('scope_articles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('status')->default('draft');
            $table->string('category')->nullable();
            $table->integer('views')->default(0);
            $table->boolean('is_featured')->default(false);
            $table->foreignId('author_id')->nullable();
            $table->timestamps();
        });

        Schema::create('scope_authors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }
};
