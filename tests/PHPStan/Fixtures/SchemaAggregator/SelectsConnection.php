<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\SchemaAggregator;

trait SelectsConnection
{
    /**
     * Run the migration on the given connection.
     *
     * @return $this
     */
    public function onConnection(string $connection): static
    {
        $this->connection = $connection;

        return $this;
    }
}
