<?php

declare(strict_types=1);

namespace Hypervel\Database;

readonly class DatabaseCliConfiguration
{
    /**
     * Create a database client configuration.
     *
     * @param list<string> $arguments
     * @param array<string, scalar> $environment
     */
    public function __construct(
        public string $command,
        public array $arguments = [],
        public array $environment = [],
    ) {
    }
}
