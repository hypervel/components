<?php

declare(strict_types=1);

use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('uuid_searchable_models', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->text('body')->nullable();
            $table->timestamps();
        });
    }
};
