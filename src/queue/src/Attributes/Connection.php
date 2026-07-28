<?php

declare(strict_types=1);

namespace Hypervel\Queue\Attributes;

use Attribute;
use UnitEnum;

use function Hypervel\Support\enum_value;

#[Attribute(Attribute::TARGET_CLASS)]
readonly class Connection
{
    public string $connection;

    /**
     * Create a new attribute instance.
     */
    public function __construct(UnitEnum|string $connection)
    {
        $this->connection = (string) enum_value($connection);
    }
}
