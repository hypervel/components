<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Hypervel\Cache\Exceptions\ValueTooLargeForColumnException;
use Swoole\Table;

class SwooleTable extends Table
{
    /**
     * The table columns.
     */
    protected array $columns;

    /**
     * Set the data type and size of the columns.
     */
    public function column(string $name, int $type, int $size = 0): bool
    {
        $this->columns[$name] = [$type, $size];

        return parent::column($name, $type, $size);
    }

    /**
     * Update a row of the table.
     */
    public function set(string $key, array $values): bool
    {
        foreach ($values as $column => $value) {
            if (! isset($this->columns[$column])) {
                continue;
            }

            [$type, $size] = $this->columns[$column];

            if ($type !== Table::TYPE_STRING) {
                continue;
            }

            $length = strlen($value);

            if ($length > $size) {
                throw new ValueTooLargeForColumnException(sprintf(
                    'Value [%s...] is too large for [%s] column. Should be less than %d characters but got %d characters.',
                    substr($value, 0, 20),
                    $column,
                    $size,
                    $length
                ));
            }
        }

        return parent::set($key, $values);
    }
}
