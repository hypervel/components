<?php

declare(strict_types=1);

use Hyperf\Database\Schema\Blueprint;
use Hypervel\Database\Migrations\Migration;
use Hypervel\Support\Facades\Schema;

use function Hypervel\Config\config;

return new class extends Migration {
    /**
     * Get the migration connection name.
     */
    public function getConnection(): string
    {
        return config('permission.storage.database.connection')
            ?: parent::getConnection();
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        $schema->create('roles', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name')->unique();
            $table->string('guard_name');
            $table->timestamps();

            $table->index(['name', 'guard_name']);
        });

        $schema->create('permissions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name')->unique();
            $table->string('guard_name');
            $table->boolean('is_forbidden');
            $table->timestamps();
            $table->index(['name', 'guard_name']);
        });
        $schema->create('role_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
            $table->boolean('is_forbidden');
            $table->timestamps();

            $table->primary(['permission_id', 'role_id']);
            $table->index('role_id');
            $table->index('permission_id');
        });

        $schema->create('owner_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->morphs('owner');
            $table->boolean('is_forbidden');
            $table->timestamps();

            $table->primary(['permission_id', 'owner_id', 'owner_type']);
            $table->index('owner_id');
            $table->index('permission_id');
        });

        $schema->create('owner_has_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->morphs('owner');
            $table->timestamps();

            $table->primary(['role_id', 'owner_id', 'owner_type']);
            $table->index('owner_id');
            $table->index('role_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->dropIfExists('model_has_roles');
        $schema->dropIfExists('model_has_permissions');
        $schema->dropIfExists('role_has_permissions');
        $schema->dropIfExists('permissions');
        $schema->dropIfExists('roles');
    }
};
