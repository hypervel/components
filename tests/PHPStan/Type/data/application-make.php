<?php

namespace ApplicationMake;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Application;
use Hypervel\Tests\PHPStan\Type\data\FailsAtRuntime;

use function PHPStan\Testing\assertType;

/** @param class-string<Model> $model */
function test(Application $app, ApplicationContract $app2, string $model): void
{
    assertType('Hypervel\Config\Repository', $app->make(Repository::class));
    assertType('Hypervel\Config\Repository', $app->makeWith(Repository::class));
    assertType('Hypervel\Config\Repository', $app->resolve(Repository::class));
    assertType('Hypervel\Tests\PHPStan\Type\data\FailsAtRuntime', $app->resolve(FailsAtRuntime::class));

    assertType('Hypervel\Config\Repository', $app2->make(Repository::class));
    // REMOVED: makeWith() and resolve() on the contract; they are concrete container
    // methods, and contracts are not given implementation methods.
    assertType('Hypervel\Tests\PHPStan\Type\data\FailsAtRuntime', $app2->make(FailsAtRuntime::class));

    // Upstream asserts mixed here; Hypervel keeps the declared class-string result.
    assertType('Hypervel\Database\Eloquent\Model', $app->make($model));
    assertType('Hypervel\Database\Eloquent\Model', $app->makeWith($model));
    assertType('Hypervel\Database\Eloquent\Model', $app->resolve($model));
}
