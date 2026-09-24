<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Hidden
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
        // A named argument keeps its name as the key, as in #[Hidden(columns: [...])].
        $columns = array_values($columns);

        $this->columns = is_array($columns[0] ?? null) ? $columns[0] : $columns;
    }
}
