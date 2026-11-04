<?php

namespace AppMake;

use Hypervel\PHPStan\ApplicationResolver;
use Hypervel\Support\Facades\App;

use function PHPStan\Testing\assertType;

function test(): void
{
    assertType(ApplicationResolver::class, App::make(ApplicationResolver::class));
    assertType(ApplicationResolver::class, App::makeWith(ApplicationResolver::class));

    // Hypervel's other resolution methods.
    assertType(ApplicationResolver::class, App::makeTransient(ApplicationResolver::class));
    assertType(ApplicationResolver::class, App::get(ApplicationResolver::class));
    assertType('Closure(): Hypervel\PHPStan\ApplicationResolver', App::factory(ApplicationResolver::class));

    // Service keys resolve to their bindings rather than to facade aliases with the same name.
    assertType('Hypervel\Config\Repository', App::make('config'));
    assertType('Closure(): Hypervel\Config\Repository', App::factory('config'));

    assertType('mixed', App::make('not-bound'));
}
