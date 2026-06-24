<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Touches
{
    /**
     * @var array<int, string>
     */
    public array $relations;

    /**
     * Create a new attribute instance.
     *
     * @param array<int, string>|string ...$relations
     */
    public function __construct(array|string ...$relations)
    {
        $this->relations = is_array($relations[0] ?? null) ? $relations[0] : $relations;
    }
}
