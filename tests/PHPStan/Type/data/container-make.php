<?php

namespace ContainerMake;

use Hypervel\Container\Container;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Database\Eloquent\Model;

use function PHPStan\Testing\assertType;

/** @param class-string<Model> $model */
function test(
    Container $container,
    ContainerContract $container2,
    string $model,
): void {
    assertType('Hypervel\Config\Repository', $container->make(Repository::class));
    assertType('Hypervel\Config\Repository', $container->makeWith(Repository::class));
    assertType('Hypervel\Config\Repository', $container->resolve(Repository::class));

    assertType('Hypervel\Config\Repository', $container2->make(Repository::class));
    // REMOVED: makeWith() and resolve() on the contract; they are concrete container
    // methods, and contracts are not given implementation methods.

    assertType('mixed', $container->make($foo));
    assertType('mixed', $container->makeWith($foo));
    assertType('mixed', $container->resolve($foo));

    // Hypervel's other resolution methods.
    assertType('Hypervel\Config\Repository', $container2->get(Repository::class));
    assertType('Hypervel\Config\Repository', $container2->makeTransient(Repository::class));
    assertType('Closure(): Hypervel\Config\Repository', $container2->factory(Repository::class));

    // Service keys resolve to their bindings rather than to facade aliases with the same name.
    assertType('Hypervel\Config\Repository', $container2->make('config'));
    assertType('Hypervel\Http\Request', $container2->make('request'));
    assertType('Closure(): Hypervel\Config\Repository', $container2->factory('config'));
    assertType('mixed', $container2->make('eloquent'));

    // A class keeps its declared type when resolution fails or needs runtime context.
    assertType('Hypervel\Contracts\Auth\Authenticatable', $container2->make(Authenticatable::class));
    assertType('Hypervel\Tests\PHPStan\Type\data\FailsAtRuntime', $container2->make('\Hypervel\Tests\PHPStan\Type\data\FailsAtRuntime'));

    // Unknown keys and scalar bindings are mixed, as the container declares.
    assertType('mixed', $container2->make('not-bound'));
    assertType('mixed', $container2->make('path'));
    assertType('mixed', $container2->make(\MissingContainerClass::class));

    assertType('Hypervel\Database\Eloquent\Model', $container2->make($model));
    assertType('Hypervel\Config\Repository', $container2->make(parameters: [], abstract: 'config'));
    assertType('mixed', $container2->make(...['config']));
}
