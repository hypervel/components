<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Guarded
{
    /**
     * @var array<int, string>
     */
    public array $columns;

    /**
     * Create a new attribute instance.
     *
     * @param array<int, string>|string ...$columns
     */
    public function __construct(array|string ...$columns)
    {
        $this->columns = is_array($columns[0] ?? null) ? $columns[0] : $columns;
    }
}
