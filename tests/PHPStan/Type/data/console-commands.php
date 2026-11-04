<?php

declare(strict_types=1);

namespace ConsoleCommands;

use App\Console\Commands\FooCommand;
use Hypervel\Console\Command;
use Hypervel\Foundation\Console\ClosureCommand;
use Hypervel\Support\Facades\Artisan;

use function PHPStan\Testing\assertType;

function test(FooCommand $foo, Command $command, FooCommand|ClosureCommand $fooOrClosure): void
{
    assertType('string', $foo->argument('argument'));
    assertType('true', $foo->hasOption('option'));

    // The base command stands for every registered command, so it keeps the declaration.
    assertType('mixed', $command->argument('argument'));

    // The closure alternative could define any name, so the union keeps the declaration.
    assertType('mixed', $fooOrClosure->argument('argument'));
    assertType('mixed', $fooOrClosure->argument('missing'));

    Artisan::command('closure', function (): void {
        // Closure commands share one class across unrelated signatures, so they keep the declaration.
        assertType('mixed', $this->argument('argument'));
    });
}
