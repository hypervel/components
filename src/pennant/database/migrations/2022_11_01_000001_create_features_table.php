<?php

declare(strict_types=1);

use Hypervel\Database\Schema\Blueprint;
use Hypervel\Database\Schema\Builder;
use Hypervel\Pennant\Migrations\PennantMigration;
use Hypervel\Support\Facades\Schema;

return new class extends PennantMigration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('features', function (Blueprint $table) {
            $table->id();

            // MySQL and MariaDB string collations ignore case, accents and trailing spaces.
            if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $table->binary('name', length: Builder::$defaultStringLength * 4);
                $table->binary('scope', length: Builder::$defaultStringLength * 4);
            } else {
                $table->string('name');
                $table->string('scope');
            }

            $table->text('value');
            $table->timestamps();

            $table->unique(['name', 'scope']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('features');
    }
};
