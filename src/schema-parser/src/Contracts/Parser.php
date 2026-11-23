<?php

declare(strict_types=1);

namespace Hypervel\SchemaParser\Contracts;

use Hypervel\SchemaParser\Schema;

interface Parser
{
    /**
     * Parse a SQL schema dump.
     */
    public function parse(string $sql): Schema;
}
