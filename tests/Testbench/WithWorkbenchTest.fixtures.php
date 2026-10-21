<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\WithWorkbenchTest;

use Hypervel\Testbench\Concerns\WithWorkbench;
use Hypervel\Testbench\Contracts\Config as ConfigContract;

class MergeSeedersTestStub
{
    use WithWorkbench;

    /**
     * Create a new merge seeders test stub instance.
     */
    public function __construct(
        protected bool $seed,
        protected string|false $seeders,
    ) {
    }

    /**
     * Merge the stub's seeders with the given Workbench configuration.
     */
    public function __invoke(ConfigContract $config): array|false
    {
        return $this->mergeSeedersForWorkbench($config);
    }

    /**
     * Determine if the seed task should be run when refreshing the database.
     */
    public function shouldSeed(): bool
    {
        return $this->seed;
    }

    /**
     * Determine the specific seeder class that should be used when refreshing the database.
     */
    public function seeder(): string|false
    {
        return $this->seeders;
    }
}
