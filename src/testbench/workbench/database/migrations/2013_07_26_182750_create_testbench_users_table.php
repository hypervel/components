<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Hypervel\Database\Migrations\Migration;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Hash;
use Hypervel\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('testbench_users', function ($table) {
            $table->increments('id');
            $table->string('email');
            $table->string('password');

            $table->timestamps();
        });

        $now = CarbonImmutable::now();

        DB::table('testbench_users')->insert([
            'email' => 'crynobone@gmail.com',
            'password' => Hash::make('123'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::drop('testbench_users');
    }
};
