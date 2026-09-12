<?php

declare(strict_types=1);

use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;

class CreatePeopleIsDynamicTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('people', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('blog_id')->nullable();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        DB::table('people')->insert([
            ['email' => 'jane@example.com', 'name' => 'Jane Doe', 'password' => 'secret'],
            ['email' => 'john@example.com', 'name' => 'John Doe', 'password' => 'secret'],
        ]);
    }
}
