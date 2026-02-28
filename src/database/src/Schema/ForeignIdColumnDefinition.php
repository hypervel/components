<?php

declare(strict_types=1);

namespace Hypervel\Database\Schema;

use Hypervel\Support\Stringable;

class ForeignIdColumnDefinition extends ColumnDefinition
{
    /**
     * The schema builder blueprint instance.
     */
    protected Blueprint $blueprint;

    /**
     * Create a new foreign ID column definition.
     */
    public function __construct(Blueprint $blueprint, array $attributes = [])
    {
        parent::__construct($attributes);

        $this->blueprint = $blueprint;
    }

    /**
     * Create a foreign key constraint on this column referencing the "id" column of the conventionally related table.
     */
    public function constrained(?string $table = null, ?string $column = null, ?string $indexName = null): ForeignKeyDefinition
    {
        $table ??= $this->table;
        $column ??= $this->referencesModelColumn ?? 'id';

        return $this->references($column, $indexName)->on($table ?? (new Stringable($this->name))->beforeLast('_' . $column)->plural()->toString());
    }

    /**
     * Specify which column this foreign ID references on another table.
     */
    public function references(string $column, ?string $indexName = null): ForeignKeyDefinition
    {
        return $this->blueprint->foreign($this->name, $indexName)->references($column);
    }
}
