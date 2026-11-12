<?php

declare(strict_types=1);

use Hypervel\Contracts\Console\Kernel as KernelContract;
use Hypervel\Foundation\Console\ClosureCommand;
use Hypervel\Foundation\Console\Kernel;

use function PHPStan\Testing\assertType;

function (KernelContract $contract, Kernel $kernel): void {
    $contract->command('contract', function (): void {
        assertType(ClosureCommand::class, $this);
    });

    $kernel->command('kernel', function (): void {
        assertType(ClosureCommand::class, $this);
    });
};
