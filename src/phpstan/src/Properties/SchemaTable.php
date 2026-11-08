<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Properties;

/** @see https://github.com/psalm/laravel-psalm-plugin/blob/master/src/SchemaTable.php */
final class SchemaTable
{
    /** @var array<string, SchemaColumn> */
    public array $columns = [];

    /** Whether every column is known, so that a missing column is a claim. */
    public bool $complete = true;

    /** @var list<string> the identity keys of the tables it inherits from or partitions */
    public array $parents = [];

    /** @var null|list<string> the search path of the connection that defined it without a schema */
    public ?array $searchPath = null;

    /** @var array<string, true> the PHP files besides the migrations that its columns were read from, such as a constant naming one */
    public array $sources = [];

    /**
     * Create a new schema table instance.
     */
    public function __construct(public string $name, public ?string $schema = null)
    {
    }

    /**
     * Add or replace a column.
     */
    public function setColumn(SchemaColumn $column): void
    {
        $this->columns[$column->name] = $column;
    }

    /**
     * Rename a column.
     */
    public function renameColumn(string $oldName, string $newName): void
    {
        // An unknown column of an incomplete table keeps an unknown type under its new name.
        if (! isset($this->columns[$oldName])) {
            if (! $this->complete) {
                $this->setColumn(new SchemaColumn($newName, null));
            }

            return;
        }

        $oldColumn = $this->columns[$oldName];

        unset($this->columns[$oldName]);

        // Branch copies of a table share their columns, so the renamed column is a new instance.
        $this->columns[$newName] = new SchemaColumn($newName, $oldColumn->type, $oldColumn->nullable, $oldColumn->options);
    }

    /**
     * Drop a column.
     */
    public function dropColumn(string $columnName): void
    {
        unset($this->columns[$columnName]);
    }
}
