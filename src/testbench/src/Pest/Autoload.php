<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Pest;

use Closure;
use Hypervel\Support\Arr;
use Hypervel\Testbench\Attributes\ResetRefreshDatabaseState;
use Pest\Plugin;
use Pest\Support\Backtrace;

Plugin::uses(WithPest::class);

/**
 * Define "setUp" hook for the test case.
 *
 * @param Closure(Closure):void $callback
 */
function setUp(Closure $callback): void
{
    Hook::attach('@setUp', Backtrace::testFile(), $callback);
}

/**
 * Define "tearDown" hook for the test case.
 *
 * @param Closure(Closure):void $callback
 */
function tearDown(Closure $callback): void
{
    Hook::attach('@tearDown', Backtrace::testFile(), $callback);
}

/**
 * Define environment for the test case.
 *
 * @param Closure(\Hypervel\Contracts\Foundation\Application):void $callback
 */
function defineEnvironment(Closure $callback): void
{
    Hook::attach('@defineEnvironment', Backtrace::testFile(), $callback);
}

/**
 * Define database migrations for the test case.
 *
 * @param Closure():void $callback
 */
function defineDatabaseMigrations(Closure $callback): void
{
    Hook::attach('@defineDatabaseMigrations', Backtrace::testFile(), $callback);
}

/**
 * Destroy database migrations for the test case.
 *
 * @param Closure():void $callback
 */
function destroyDatabaseMigrations(Closure $callback): void
{
    Hook::attach('@destroyDatabaseMigrations', Backtrace::testFile(), $callback);
}

/**
 * Define database seeders for the test case.
 *
 * @param Closure():void $callback
 */
function defineDatabaseSeeders(Closure $callback): void
{
    Hook::attach('@defineDatabaseSeeders', Backtrace::testFile(), $callback);
}

/**
 * Define routes for the test case.
 *
 * @param Closure(\Hypervel\Routing\Router):void $callback
 */
function defineRoutes(Closure $callback): void
{
    Hook::attach('@defineRoutes', Backtrace::testFile(), $callback);
}

/**
 * Define web routes for the test case.
 *
 * @param Closure(\Hypervel\Routing\Router):void $callback
 */
function defineWebRoutes(Closure $callback): void
{
    Hook::attach('@defineWebRoutes', Backtrace::testFile(), $callback);
}

/**
 * Define "afterApplicationCreated" hook for the test case.
 *
 * @param Closure():void $callback
 */
function afterApplicationCreated(Closure $callback): void
{
    Hook::attach('@afterApplicationCreated', Backtrace::testFile(), $callback);
}

/**
 * Define "beforeApplicationDestroyed" hook for the test case.
 *
 * @param Closure():void $callback
 */
function beforeApplicationDestroyed(Closure $callback): void
{
    Hook::attach('@beforeApplicationDestroyed', Backtrace::testFile(), $callback);
}

/**
 * Use testing feature for the test case.
 */
function usesTestingFeature(object ...$attributes): void
{
    Hook::attach('@usesTestingFeature', Backtrace::testFile(), Arr::wrap($attributes));
}

/**
 * Reset refresh database state.
 */
function resetRefreshDatabaseState(): void
{
    usesTestingFeature(new ResetRefreshDatabaseState());
}
