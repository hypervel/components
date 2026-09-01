<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Facades;

use Closure;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Support\Facades\Facade;

/**
 * @method static void assertNothingSent()
 * @method static void assertNotSent(callable|string $value)
 * @method static void assertSent(callable|string $value)
 * @method static void assertSentCount(int $count, null|string $requestClass = null)
 * @method static void assertSentInOrder(array<int, callable|string> $callbacks)
 * @method static \Hypervel\Contracts\Cache\Factory cache()
 * @method static \Hypervel\Saloon\SaloonManager clearFake()
 * @method static \Hypervel\Saloon\Http\Faking\MockClient fake(array<array-key, callable|\Hypervel\Saloon\Http\Faking\Fixture|\Hypervel\Saloon\Http\Faking\MockResponse>|\Hypervel\Saloon\Http\Faking\MockClient $responses = [])
 * @method static \Hypervel\Saloon\SaloonManager fixturePath(string $path)
 * @method static string getFixturePath()
 * @method static \Hypervel\Saloon\Http\MiddlewarePipeline middleware()
 * @method static \Hypervel\Saloon\Http\Faking\MockClient|null mockClient()
 * @method static \Hypervel\RateLimiter\RateLimiter rateLimiter()
 * @method static string|null resolveCacheScope(\Hypervel\Saloon\Http\PendingRequest $pendingRequest)
 * @method static \Hypervel\Saloon\SaloonManager resolveCacheScopeUsing(null|Closure $resolver)
 * @method static \Hypervel\Saloon\Http\Response<mixed> send(\Hypervel\Saloon\Http\Connector $connector, \Hypervel\Saloon\Http\Request<mixed> $request, \Hypervel\Saloon\Http\Faking\MockClient|null $mockClient = null)
 * @method static \Hypervel\Saloon\Http\Sender sender()
 * @method static \Hypervel\Saloon\SaloonManager throwOnMissingFixtures(bool $throw = true)
 * @method static bool throwsOnMissingFixtures()
 *
 * @see \Hypervel\Saloon\SaloonManager
 */
class Saloon extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'saloon';
    }
}
