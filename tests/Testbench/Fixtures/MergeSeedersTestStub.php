<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Fixtures;

use Hypervel\Testbench\Concerns\WithWorkbench;
use Hypervel\Testbench\Contracts\Config as ConfigContract;

class MergeSeedersTestStub
{
    use WithWorkbench;

    public function __construct(
        protected bool $seed,
        protected string|false $seeders,
    ) {
    }

    public function __invoke(ConfigContract $config): array|false
    {
        return $this->mergeSeedersForWorkbench($config);
    }

    public function shouldSeed(): bool
    {
        return $this->seed;
    }

    public function seeder(): string|false
    {
        return $this->seeders;
    }
}
