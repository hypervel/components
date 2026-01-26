<?php

declare(strict_types=1);

use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('rel_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamps();
        });

        Schema::create('rel_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('rel_users')->onDelete('cascade');
            $table->string('bio')->nullable();
            $table->string('avatar')->nullable();
            $table->timestamps();
        });

        Schema::create('rel_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('rel_users')->onDelete('cascade');
            $table->string('title');
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('rel_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('rel_post_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('rel_posts')->onDelete('cascade');
            $table->foreignId('tag_id')->constrained('rel_tags')->onDelete('cascade');
            $table->timestamps();
        });

        Schema::create('rel_comments', function (Blueprint $table) {
            $table->id();
            $table->morphs('commentable');
            $table->foreignId('user_id')->constrained('rel_users')->onDelete('cascade');
            $table->text('body');
            $table->timestamps();
        });
    }
};
