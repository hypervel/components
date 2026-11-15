<?php

declare(strict_types=1);

namespace Hypervel\Validation\Rules;

use Hypervel\Contracts\Container\Transient;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Traits\Conditionable;
use Stringable;

class Unique implements Stringable, Transient
{
    use Conditionable;
    use DatabaseRule;

    /**
     * The ID that should be ignored.
     */
    protected mixed $ignore = null;

    /**
     * The name of the ID column.
     */
    protected string $idColumn = 'id';

    /**
     * Ignore the given ID during the unique check.
     *
     * @return $this
     */
    public function ignore(mixed $id, ?string $idColumn = null): static
    {
        if ($id instanceof Model) {
            return $this->ignoreModel($id, $idColumn);
        }

        $this->ignore = $id;
        $this->idColumn = $idColumn ?? 'id';

        return $this;
    }

    /**
     * Ignore the given model during the unique check.
     *
     * @return $this
     */
    public function ignoreModel(Model $model, ?string $idColumn = null): static
    {
        $this->idColumn = $idColumn ?? $model->getKeyName();
        $this->ignore = $model->{$this->idColumn};

        return $this;
    }

    /**
     * Convert the rule to a validation string.
     */
    final public function __toString(): string
    {
        [$table, $column, $ignore, $idColumn] = $this->queryParameters();

        return rtrim(sprintf(
            'unique:%s,%s,%s,%s,%s',
            $table,
            $column,
            $ignore !== null ? '"' . str_replace('"', '""', $ignore) . '"' : 'NULL',
            $idColumn,
            $this->formatWheres()
        ), ',');
    }

    /**
     * Get the table, column and exclusion used by validation and string rendering.
     *
     * @return array{string, string, ?string, string}
     */
    public function queryParameters(): array
    {
        return [$this->table, $this->column, $this->ignore === null ? null : (string) $this->ignore, $this->idColumn];
    }
}
