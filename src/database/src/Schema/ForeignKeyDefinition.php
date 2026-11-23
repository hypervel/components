<?php

declare(strict_types=1);

namespace Hypervel\Database\Schema;

use Hypervel\Support\Fluent;

/**
 * @method $this deferrable(bool $value = true) Set the foreign key as deferrable (PostgreSQL)
 * @method $this initiallyImmediate(bool $value = true) Set the default time to check the constraint (PostgreSQL)
 * @method $this inplace(bool $value = true) Specify that algorithm=inplace should be used for the foreign key operation, which requires foreign key checks to be disabled (MySQL)
 * @method $this lock(('default'|'exclusive'|'none'|'shared') $value) Specify the DDL lock mode for the foreign key operation (MySQL)
 * @method $this on(string $table) Specify the referenced table
 * @method $this onDelete(string $action) Add an ON DELETE action
 * @method $this onUpdate(string $action) Add an ON UPDATE action
 * @method $this references(string|string[] $columns) Specify the referenced column(s)
 */
class ForeignKeyDefinition extends Fluent
{
    /**
     * Indicate that updates should cascade.
     *
     * @return $this
     */
    public function cascadeOnUpdate(): self
    {
        return $this->onUpdate('cascade');
    }

    /**
     * Indicate that updates should be restricted.
     *
     * @return $this
     */
    public function restrictOnUpdate(): self
    {
        return $this->onUpdate('restrict');
    }

    /**
     * Indicate that updates should set the foreign key value to null.
     *
     * @return $this
     */
    public function nullOnUpdate(): self
    {
        return $this->onUpdate('set null');
    }

    /**
     * Indicate that updates should have "no action".
     *
     * @return $this
     */
    public function noActionOnUpdate(): self
    {
        return $this->onUpdate('no action');
    }

    /**
     * Indicate that deletes should cascade.
     *
     * @return $this
     */
    public function cascadeOnDelete(): self
    {
        return $this->onDelete('cascade');
    }

    /**
     * Indicate that deletes should be restricted.
     *
     * @return $this
     */
    public function restrictOnDelete(): self
    {
        return $this->onDelete('restrict');
    }

    /**
     * Indicate that deletes should set the foreign key value to null.
     *
     * @return $this
     */
    public function nullOnDelete(): self
    {
        return $this->onDelete('set null');
    }

    /**
     * Indicate that deletes should have "no action".
     *
     * @return $this
     */
    public function noActionOnDelete(): self
    {
        return $this->onDelete('no action');
    }
}
