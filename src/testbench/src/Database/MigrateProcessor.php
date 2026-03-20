<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Database;

use Hypervel\Testbench\Contracts\TestCase;

use function Hypervel\Testbench\artisan;

/**
 * @internal
 */
class MigrateProcessor
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        protected readonly TestCase $testbench,
        protected readonly array $options = [],
    ) {
    }

    public function up(): static
    {
        $this->dispatch('migrate');

        return $this;
    }

    public function rollback(): static
    {
        $this->dispatch('migrate:rollback');

        return $this;
    }

    protected function dispatch(string $command): void
    {
        artisan($this->testbench, $command, $this->options);
    }
}
