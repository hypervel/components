<?php

declare(strict_types=1);

namespace Hypervel\Database\Schema;

use Hypervel\Support\Fluent;

/**
 * @method ForeignKeyDefinition deferrable(bool $value = true) Set the foreign key as deferrable (PostgreSQL)
 * @method ForeignKeyDefinition initiallyImmediate(bool $value = true) Set the default time to check the constraint (PostgreSQL)
 * @method ForeignKeyDefinition lock(string $value) Specify the DDL lock mode for the foreign key operation (MySQL)
 * @method ForeignKeyDefinition on(string $table) Specify the referenced table
 * @method ForeignKeyDefinition onDelete(string $action) Add an ON DELETE action
 * @method ForeignKeyDefinition onUpdate(string $action) Add an ON UPDATE action
 * @method ForeignKeyDefinition references(string|array $columns) Specify the referenced column(s)
 */
class ForeignKeyDefinition extends Fluent
{
    /**
     * Indicate that updates should cascade.
     */
    public function cascadeOnUpdate(): self
    {
        return $this->onUpdate('cascade');
    }

    /**
     * Indicate that updates should be restricted.
     */
    public function restrictOnUpdate(): self
    {
        return $this->onUpdate('restrict');
    }

    /**
     * Indicate that updates should set the foreign key value to null.
     */
    public function nullOnUpdate(): self
    {
        return $this->onUpdate('set null');
    }

    /**
     * Indicate that updates should have "no action".
     */
    public function noActionOnUpdate(): self
    {
        return $this->onUpdate('no action');
    }

    /**
     * Indicate that deletes should cascade.
     */
    public function cascadeOnDelete(): self
    {
        return $this->onDelete('cascade');
    }

    /**
     * Indicate that deletes should be restricted.
     */
    public function restrictOnDelete(): self
    {
        return $this->onDelete('restrict');
    }

    /**
     * Indicate that deletes should set the foreign key value to null.
     */
    public function nullOnDelete(): self
    {
        return $this->onDelete('set null');
    }

    /**
     * Indicate that deletes should have "no action".
     */
    public function noActionOnDelete(): self
    {
        return $this->onDelete('no action');
    }
}
