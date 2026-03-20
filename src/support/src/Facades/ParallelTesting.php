<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

/**
 * @method static void resolveOptionsUsing(\Closure|null $resolver)
 * @method static void resolveTokenUsing(\Closure|null $resolver)
 * @method static void setUpProcess(callable $callback)
 * @method static void setUpTestCase(callable $callback)
 * @method static void setUpTestDatabaseBeforeMigrating(callable $callback)
 * @method static void setUpTestDatabase(callable $callback)
 * @method static void tearDownProcess(callable $callback)
 * @method static void tearDownTestCase(callable $callback)
 * @method static void callSetUpProcessCallbacks()
 * @method static void callSetUpTestCaseCallbacks(mixed $testCase)
 * @method static void callSetUpTestDatabaseBeforeMigratingCallbacks(string $database)
 * @method static void callSetUpTestDatabaseCallbacks(string $database)
 * @method static void callTearDownProcessCallbacks()
 * @method static void callTearDownTestCaseCallbacks(mixed $testCase)
 * @method static mixed option(string $option)
 * @method static string|false token()
 * @method static bool inParallel()
 *
 * @see \Hypervel\Testing\ParallelTesting
 */
class ParallelTesting extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return \Hypervel\Testing\ParallelTesting::class;
    }
}
