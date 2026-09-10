<?php

declare(strict_types=1);

namespace Hypervel\Types\Database\Schema;

use Hypervel\Database\Schema\Blueprint;
use Hypervel\Database\Schema\ColumnDefinition;

use function PHPStan\Testing\assertType;

/**
 * Verify the default column definition type.
 */
function testColumnDefinitionsUseTheDefaultType(Blueprint $table): void
{
    assertType('Hypervel\Database\Schema\ColumnDefinition', $table->string('name'));
    assertType('Hypervel\Database\Schema\ColumnDefinition', $table->softDeletes()->nullable());
    assertType('Hypervel\Support\Collection<int, Hypervel\Database\Schema\ColumnDefinition>', $table->timestamps());
    assertType('Hypervel\Database\Schema\ColumnDefinition', $table->timestamp('created_at')->useCurrent()->storedAs(null));
    assertType('Hypervel\Database\Schema\ColumnDefinition', $table->timestamp('created_at')->useCurrent()->storedAs(null)->change());
    assertType('Hypervel\Database\Schema\ColumnDefinition', $table->integer('value')->virtualAs(null));
    assertType('Hypervel\Database\Schema\ColumnDefinition', $table->integer('value')->virtualAs(null)->change());
}

/**
 * Verify factory-created returns without narrowing heterogeneous column storage.
 */
function testCustomColumnDefinitionsUseTheFactoryType(CustomBlueprint $table): void
{
    assertType('Hypervel\Types\Database\Schema\CustomColumnDefinition', $table->string('name')->nullable()->label('Display name'));
    assertType('Hypervel\Types\Database\Schema\CustomColumnDefinition', $table->unsignedBigInteger('count'));
    assertType('Hypervel\Types\Database\Schema\CustomColumnDefinition', $table->softDeletes());
    assertType('Hypervel\Types\Database\Schema\CustomColumnDefinition', $table->timestamp('created_at')->storedAs(null)->change()->label('Created'));
    assertType('Hypervel\Types\Database\Schema\CustomColumnDefinition', $table->integer('value')->virtualAs(null)->change()->label('Value'));
    assertType('Hypervel\Types\Database\Schema\CustomColumnDefinition', $table->addColumn('string', 'title'));
    assertType('Hypervel\Support\Collection<int, Hypervel\Types\Database\Schema\CustomColumnDefinition>', $table->timestamps());
    assertType('Hypervel\Support\Collection<int, Hypervel\Types\Database\Schema\CustomColumnDefinition>', $table->datetimes());
    assertType('Hypervel\Database\Schema\ForeignIdColumnDefinition', $table->foreignId('author_id'));
    assertType('Hypervel\Database\Schema\ForeignIdColumnDefinition', $table->foreignUuid('owner_id'));
    assertType('Hypervel\Database\Schema\ForeignKeyDefinition', $table->foreignId('team_id')->constrained());
    assertType('list<Hypervel\Database\Schema\ColumnDefinition>', $table->getColumns());
    assertType('array<int, Hypervel\Database\Schema\ColumnDefinition>', $table->getAddedColumns());
}

function testIndexDefinitionsUseConcreteTypes(Blueprint $table): void
{
    assertType('Hypervel\Database\Schema\IndexDefinition', $table->primary('id'));
    assertType('Hypervel\Database\Schema\IndexDefinition', $table->unique('email'));
    assertType('Hypervel\Database\Schema\IndexDefinition', $table->index('name'));
    assertType('Hypervel\Database\Schema\IndexDefinition', $table->fullText('body'));
    assertType('Hypervel\Database\Schema\IndexDefinition', $table->spatialIndex('location'));
    assertType('Hypervel\Database\Schema\IndexDefinition', $table->vectorIndex('embedding'));
    assertType('Hypervel\Database\Schema\IndexDefinition', $table->rawIndex('(lower(email))', 'users_email_lower_index'));
    assertType(
        'Hypervel\Database\Schema\IndexDefinition',
        $table->index('archived_at')->whereNotNull('archived_at'),
    );
}

class CustomColumnDefinition extends ColumnDefinition
{
    /**
     * Set the column's display label.
     */
    public function label(string $label): static
    {
        return $this->set('label', $label);
    }
}

/**
 * @extends Blueprint<CustomColumnDefinition>
 */
class CustomBlueprint extends Blueprint
{
    /**
     * Create a new column definition.
     *
     * @param array<array-key, mixed> $attributes
     */
    protected function newColumnDefinition(array $attributes): CustomColumnDefinition
    {
        return new CustomColumnDefinition($attributes);
    }
}
