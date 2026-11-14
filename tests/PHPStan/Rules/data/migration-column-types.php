<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\MigrationColumnTypes;

use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Database\Schema\ColumnDefinition;
use Hypervel\Support\Facades\Schema;

class AuditBlueprint extends Blueprint
{
    public function json(string $column): ColumnDefinition
    {
        return $this->jsonb($column);
    }
}

class CreateOrdersTable extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->enum('status', ['pending', 'paid']);
            $table->set('flags', ['gift', 'rush']);
            $table->json('metadata')->nullable();
            $table->string('state');
            $table->jsonb('options');
            $table->text('payload');
        });
    }

    public function audit(AuditBlueprint $table): void
    {
        $table->json('changes');
    }
}
