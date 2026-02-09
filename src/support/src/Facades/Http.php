<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\HttpClient\Factory;
use Hypervel\HttpClient\Response;
use Hypervel\HttpClient\ResponseSequence;

/**
 * @method static \Hypervel\HttpClient\Factory globalMiddleware(callable $middleware)
 * @method static \Hypervel\HttpClient\Factory globalRequestMiddleware(callable $middleware)
 * @method static \Hypervel\HttpClient\Factory globalResponseMiddleware(callable $middleware)
 * @method static \Hypervel\HttpClient\Factory globalOptions(\Closure|array $options)
 * @method static \GuzzleHttp\Promise\PromiseInterface response(\GuzzleHttp\Promise\PromiseInterface|\Hypervel\HttpClient\Response|callable|array|string|int|null $body = null, int $status = 200, array $headers = [])
 * @method static \Closure failedConnection(string|null $message = null)
 * @method static \Hypervel\HttpClient\ResponseSequence sequence(array $responses = [])
 * @method static \Hypervel\HttpClient\Factory fake(callable|array|null $callback = null)
 * @method static \Hypervel\HttpClient\ResponseSequence fakeSequence(string $url = '*')
 * @method static \Hypervel\HttpClient\Factory stubUrl(string $url, \GuzzleHttp\Promise\PromiseInterface|\Hypervel\HttpClient\Response|callable|array|string|int $callback)
 * @method static \Hypervel\HttpClient\Factory preventStrayRequests(bool $prevent = true)
 * @method static bool preventingStrayRequests()
 * @method static \Hypervel\HttpClient\Factory allowStrayRequests(array|null $only = null)
 * @method static void recordRequestResponsePair(\Hypervel\HttpClient\Request $request, \Hypervel\HttpClient\Response|null $response)
 * @method static void assertSent(callable $callback)
 * @method static void assertSentInOrder(array $callbacks)
 * @method static void assertNotSent(callable $callback)
 * @method static void assertNothingSent()
 * @method static void assertSentCount(int $count)
 * @method static void assertSequencesAreEmpty()
 * @method static \Hypervel\Support\Collection recorded(callable|null $callback = null)
 * @method static \Hypervel\HttpClient\PendingRequest createPendingRequest()
 * @method static \Psr\EventDispatcher\EventDispatcherInterface|null getDispatcher()
 * @method static array getGlobalMiddleware()
 * @method static \Hypervel\HttpClient\Factory registerConnection(string $name, array $config = [])
 * @method static \GuzzleHttp\ClientInterface getClient(string|null $connection, \GuzzleHttp\HandlerStack $handlerStack, array|null $config = null)
 * @method static \GuzzleHttp\ClientInterface createClient(\GuzzleHttp\HandlerStack $handlerStack)
 * @method static array getConnectionConfigs()
 * @method static array getConnectionConfig(string $name)
 * @method static \Hypervel\HttpClient\Factory setConnectionConfig(string $name, array $config)
 * @method static \Hypervel\HttpClient\Factory setReleaseCallback(string $driver, \Closure $callback)
 * @method static \Closure|null getReleaseCallback(string $driver)
 * @method static \Hypervel\HttpClient\Factory addPoolable(string $driver)
 * @method static \Hypervel\HttpClient\Factory removePoolable(string $driver)
 * @method static array getPoolables()
 * @method static \Hypervel\HttpClient\Factory setPoolables(array $poolables)
 * @method static mixed macroCall(string $method, array $parameters)
 * @method static void macro(string $name, callable|object $macro)
 * @method static void mixin(object $mixin, bool $replace = true)
 * @method static bool hasMacro(string $name)
 * @method static \Hypervel\HttpClient\PendingRequest baseUrl(string $url)
 * @method static \Hypervel\HttpClient\PendingRequest withBody(\Psr\Http\Message\StreamInterface|string $content, string $contentType = 'application/json')
 * @method static \Hypervel\HttpClient\PendingRequest asJson()
 * @method static \Hypervel\HttpClient\PendingRequest asForm()
 * @method static \Hypervel\HttpClient\PendingRequest attach(array|string $name, resource|string $contents = '', string|null $filename = null, array $headers = [])
 * @method static \Hypervel\HttpClient\PendingRequest asMultipart()
 * @method static \Hypervel\HttpClient\PendingRequest bodyFormat(string $format)
 * @method static \Hypervel\HttpClient\PendingRequest withQueryParameters(array $parameters)
 * @method static \Hypervel\HttpClient\PendingRequest contentType(string $contentType)
 * @method static \Hypervel\HttpClient\PendingRequest acceptJson()
 * @method static \Hypervel\HttpClient\PendingRequest accept(string $contentType)
 * @method static \Hypervel\HttpClient\PendingRequest withHeaders(array $headers)
 * @method static \Hypervel\HttpClient\PendingRequest withHeader(string $name, mixed $value)
 * @method static \Hypervel\HttpClient\PendingRequest replaceHeaders(array $headers)
 * @method static \Hypervel\HttpClient\PendingRequest withBasicAuth(string $username, string $password)
 * @method static \Hypervel\HttpClient\PendingRequest withDigestAuth(string $username, string $password)
 * @method static \Hypervel\HttpClient\PendingRequest withToken(string $token, string $type = 'Bearer')
 * @method static \Hypervel\HttpClient\PendingRequest withUserAgent(string|bool $userAgent)
 * @method static \Hypervel\HttpClient\PendingRequest withUrlParameters(array $parameters = [])
 * @method static \Hypervel\HttpClient\PendingRequest withCookies(array $cookies, string $domain)
 * @method static \Hypervel\HttpClient\PendingRequest maxRedirects(int $max)
 * @method static \Hypervel\HttpClient\PendingRequest withoutRedirecting()
 * @method static \Hypervel\HttpClient\PendingRequest withoutVerifying()
 * @method static \Hypervel\HttpClient\PendingRequest sink(resource|string $to)
 * @method static \Hypervel\HttpClient\PendingRequest timeout(int|float $seconds)
 * @method static \Hypervel\HttpClient\PendingRequest connectTimeout(int|float $seconds)
 * @method static \Hypervel\HttpClient\PendingRequest retry(array|int $times, \Closure|int $sleepMilliseconds = 0, callable|null $when = null, bool $throw = true)
 * @method static \Hypervel\HttpClient\PendingRequest withOptions(array $options)
 * @method static \Hypervel\HttpClient\PendingRequest withMiddleware(callable $middleware)
 * @method static \Hypervel\HttpClient\PendingRequest withRequestMiddleware(callable $middleware)
 * @method static \Hypervel\HttpClient\PendingRequest withResponseMiddleware(callable $middleware)
 * @method static \Hypervel\HttpClient\PendingRequest beforeSending(callable $callback)
 * @method static \Hypervel\HttpClient\PendingRequest throw(callable|null $callback = null)
 * @method static \Hypervel\HttpClient\PendingRequest throwIf(callable|bool $condition)
 * @method static \Hypervel\HttpClient\PendingRequest throwUnless(callable|bool $condition)
 * @method static \Hypervel\HttpClient\PendingRequest dump()
 * @method static \Hypervel\HttpClient\PendingRequest dd()
 * @method static \GuzzleHttp\Promise\PromiseInterface|\Hypervel\HttpClient\Response get(string $url, \JsonSerializable|array|string|null $query = null)
 * @method static \GuzzleHttp\Promise\PromiseInterface|\Hypervel\HttpClient\Response head(string $url, array|string|null $query = null)
 * @method static \GuzzleHttp\Promise\PromiseInterface|\Hypervel\HttpClient\Response post(string $url, \JsonSerializable|array $data = [])
 * @method static \GuzzleHttp\Promise\PromiseInterface|\Hypervel\HttpClient\Response patch(string $url, array $data = [])
 * @method static \GuzzleHttp\Promise\PromiseInterface|\Hypervel\HttpClient\Response put(string $url, array $data = [])
 * @method static \GuzzleHttp\Promise\PromiseInterface|\Hypervel\HttpClient\Response delete(string $url, array $data = [])
 * @method static \GuzzleHttp\Promise\PromiseInterface|\Hypervel\HttpClient\Response send(string $method, string $url, array $options = [])
 * @method static \GuzzleHttp\ClientInterface buildClient()
 * @method static \GuzzleHttp\HandlerStack buildHandlerStack()
 * @method static \GuzzleHttp\HandlerStack pushHandlers(\GuzzleHttp\HandlerStack $handlerStack)
 * @method static \Closure buildBeforeSendingHandler()
 * @method static \Closure buildRecorderHandler()
 * @method static \Closure buildStubHandler()
 * @method static \Psr\Http\Message\RequestInterface runBeforeSendingCallbacks(\Psr\Http\Message\RequestInterface $request, array $options)
 * @method static array mergeOptions(mixed ...$options)
 * @method static \Hypervel\HttpClient\PendingRequest stub(\Hypervel\Support\Collection|callable $callback)
 * @method static \Hypervel\HttpClient\PendingRequest async(bool $async = true)
 * @method static \GuzzleHttp\Promise\PromiseInterface|null getPromise()
 * @method static \Hypervel\HttpClient\PendingRequest setClient(\GuzzleHttp\ClientInterface $client)
 * @method static \Hypervel\HttpClient\PendingRequest setHandler(callable $handler)
 * @method static array getOptions()
 * @method static \Hypervel\HttpClient\PendingRequest connection(string $connection, array|null $config = null)
 * @method static string|null getConnection()
 * @method static \Hypervel\HttpClient\PendingRequest|mixed when(null|\Closure|mixed $value = null, null|callable $callback = null, null|callable $default = null, null|\Closure|mixed $value = null)
 * @method static \Hypervel\HttpClient\PendingRequest|mixed unless(null|\Closure|mixed $value = null, null|callable $callback = null, null|callable $default = null, null|\Closure|mixed $value = null)
 *
 * @see \Hypervel\HttpClient\Factory
 */
class Http extends Facade
{
    /**
     * Register a stub callable that will intercept requests and be able to return stub responses.
     */
    public static function fake(array|callable|null $callback = null): Factory
    {
        return tap(static::getFacadeRoot(), function (Factory $fake) use ($callback) {
            static::swap($fake->fake($callback));
        });
    }

    /**
     * Register a response sequence for the given URL pattern.
     */
    public static function fakeSequence(string $urlPattern = '*'): ResponseSequence
    {
        $fake = tap(static::getFacadeRoot(), function (Factory $fake) {
            static::swap($fake);
        });

        return $fake->fakeSequence($urlPattern);
    }

    /**
     * Indicate that an exception should be thrown if any request is not faked.
     */
    public static function preventStrayRequests(bool $prevent = true): Factory
    {
        return tap(static::getFacadeRoot(), function (Factory $fake) use ($prevent) {
            static::swap($fake->preventStrayRequests($prevent));
        });
    }

    /**
     * Stub the given URL using the given callback.
     */
    public static function stubUrl(
        string $url,
        array|callable|int|PromiseInterface|Response|string $callback
    ): Factory {
        return tap(static::getFacadeRoot(), function (Factory $fake) use ($url, $callback) {
            static::swap($fake->stubUrl($url, $callback));
        });
    }

    protected static function getFacadeAccessor()
    {
        return Factory::class;
    }
}
