<?php

declare(strict_types=1);

use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;
use ModelPropertyDependency\Models\FlagColumns;

return new class extends Migration {
    /**
     * Get the migration connection name.
     */
    public function getConnection(): ?string
    {
        return config('schema.connection');
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('flags', function (Blueprint $table) {
            $table->id();
            $table->string(FlagColumns::NAME);
        });

        if (Schema::hasColumn('flags', 'nickname')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('nickname');
            });
        }
    }
};
