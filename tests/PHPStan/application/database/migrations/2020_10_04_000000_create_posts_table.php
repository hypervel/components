<?php

declare(strict_types=1);

namespace Database\Migrations;

use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;

class CreatePostsTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('posts', static function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('posts_users', static function (Blueprint $table) {
            $table->string('user_id');
            $table->string('post_id');
            $table->timestamps();
        });
    }
}
