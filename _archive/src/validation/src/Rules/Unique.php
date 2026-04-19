<?php

declare(strict_types=1);

namespace Hypervel\Validation\Rules;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Traits\Conditionable;
use Stringable;

class Unique implements Stringable
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
    public function __toString(): string
    {
        return rtrim(sprintf(
            'unique:%s,%s,%s,%s,%s',
            $this->table,
            $this->column,
            $this->ignore ? '"' . addslashes((string) $this->ignore) . '"' : 'NULL',
            $this->idColumn,
            $this->formatWheres()
        ), ',');
    }
}
