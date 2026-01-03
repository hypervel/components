<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pivot_events_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('pivot_events_roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('pivot_events_role_user', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('role_id');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->primary(['user_id', 'role_id']);

            $table->foreign('user_id')
                ->references('id')
                ->on('pivot_events_users')
                ->onDelete('cascade');

            $table->foreign('role_id')
                ->references('id')
                ->on('pivot_events_roles')
                ->onDelete('cascade');
        });

        // MorphToMany test tables
        Schema::create('pivot_events_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('pivot_events_videos', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('pivot_events_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('pivot_events_taggables', function (Blueprint $table) {
            $table->unsignedBigInteger('tag_id');
            $table->unsignedBigInteger('taggable_id');
            $table->string('taggable_type');
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->primary(['tag_id', 'taggable_id', 'taggable_type']);

            $table->foreign('tag_id')
                ->references('id')
                ->on('pivot_events_tags')
                ->onDelete('cascade');
        });
    }
};
