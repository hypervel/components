<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Closure;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Arr;
use Hypervel\Support\Benchmark;
use Hypervel\Support\Collection;
use Hypervel\Support\Js;
use Hypervel\Support\Number;
use Hypervel\Support\Str;
use Hypervel\Support\Testing\Fakes\Fake;
use Hypervel\Support\Uri;
use Mockery;
use Mockery\LegacyMockInterface;
use RuntimeException;

abstract class Facade
{
    /**
     * The application instance being facaded.
     */
    protected static $app;

    /**
     * The resolved object instances.
     */
    protected static array $resolvedInstance;

    /**
     * Indicates if the resolved instance should be cached.
     *
     * When false, the facade resolves from the container on every access
     * instead of caching in $resolvedInstance. Useful for stateful services
     * like Pipeline where each usage needs a fresh instance.
     *
     * Important: for $cached = false to produce fresh instances, the
     * underlying class must be explicitly bound with bind(), not singleton().
     * Our container auto-singletons unbound classes, so an unbound class
     * would still return the same instance from the container regardless
     * of this setting. This differs from Laravel where unbound classes
     * always resolve fresh.
     */
    protected static bool $cached = true;

    /**
     * Run a Closure when the facade has been resolved.
     */
    public static function resolved(Closure $callback): void
    {
        $accessor = static::getFacadeAccessor();

        if (static::$app->resolved($accessor) === true) {
            $callback(static::getFacadeRoot(), static::$app);
        }

        static::$app->afterResolving($accessor, function ($service, $app) use ($callback) {
            $callback($service, $app);
        });
    }

    /**
     * Convert the facade into a Mockery spy.
     */
    public static function spy()
    {
        if (static::isMock()) {
            return null;
        }

        $class = static::getMockableClass();

        return tap($class ? Mockery::spy($class) : Mockery::spy(), function ($spy) {
            static::swap($spy);
        });
    }

    /**
     * Initiate a partial mock on the facade.
     */
    public static function partialMock()
    {
        $name = static::getFacadeAccessor();

        $mock = static::isMock()
            ? static::$resolvedInstance[$name]
            : static::createFreshMockInstance();

        return $mock->makePartial();
    }

    /**
     * Initiate a mock expectation on the facade.
     */
    public static function shouldReceive()
    {
        $name = static::getFacadeAccessor();

        $mock = static::isMock()
                    ? static::$resolvedInstance[$name]
                    : static::createFreshMockInstance();

        return $mock->shouldReceive(...func_get_args());
    }

    /**
     * Initiate a mock expectation on the facade.
     */
    public static function expects()
    {
        $name = static::getFacadeAccessor();

        $mock = static::isMock()
                    ? static::$resolvedInstance[$name]
                    : static::createFreshMockInstance();

        return $mock->expects(...func_get_args());
    }

    /**
     * Create a fresh mock instance for the given class.
     */
    protected static function createFreshMockInstance()
    {
        return tap(static::createMock(), function ($mock) {
            static::swap($mock);

            $mock->shouldAllowMockingProtectedMethods();
        });
    }

    /**
     * Create a fresh mock instance for the given class.
     */
    protected static function createMock()
    {
        $class = static::getMockableClass();

        return $class ? Mockery::mock($class) : Mockery::mock();
    }

    /**
     * Determines whether a mock is set as the instance of the facade.
     */
    protected static function isMock(): bool
    {
        $name = static::getFacadeAccessor();

        return isset(static::$resolvedInstance[$name])
               && static::$resolvedInstance[$name] instanceof LegacyMockInterface;
    }

    /**
     * Get the mockable class for the bound instance.
     */
    protected static function getMockableClass(): ?string
    {
        if ($root = static::getFacadeRoot()) {
            return get_class($root);
        }

        return null;
    }

    /**
     * Hotswap the underlying instance behind the facade.
     */
    public static function swap(mixed $instance)
    {
        static::$resolvedInstance[static::getFacadeAccessor()] = $instance;

        if (isset(static::$app)) {
            static::$app->instance(static::getFacadeAccessor(), $instance);
        }
    }

    /**
     * Determines whether a "fake" has been set as the facade instance.
     */
    public static function isFake(): bool
    {
        $name = static::getFacadeAccessor();

        return isset(static::$resolvedInstance[$name])
            && static::$resolvedInstance[$name] instanceof Fake;
    }

    /**
     * Get the root object behind the facade.
     */
    public static function getFacadeRoot(): mixed
    {
        return static::resolveFacadeInstance(static::getFacadeAccessor());
    }

    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        throw new RuntimeException('Facade does not implement getFacadeAccessor method.');
    }

    /**
     * Resolve the facade root instance from the container.
     */
    protected static function resolveFacadeInstance(string $name): mixed
    {
        if (isset(static::$resolvedInstance[$name])) {
            return static::$resolvedInstance[$name];
        }

        if (static::$app) {
            if (static::$cached) {
                return static::$resolvedInstance[$name] = static::$app[$name];
            }

            return static::$app[$name];
        }

        return null;
    }

    /**
     * Clear a resolved facade instance.
     */
    public static function clearResolvedInstance(?string $name = null): void
    {
        unset(static::$resolvedInstance[$name ?? static::getFacadeAccessor()]);
    }

    /**
     * Clear all of the resolved instances.
     */
    public static function clearResolvedInstances(): void
    {
        static::$resolvedInstance = [];
    }

    /**
     * Get the application default aliases.
     */
    public static function defaultAliases(): Collection
    {
        // Note: Redis is intentionally excluded — the alias would shadow
        // the \Redis class from the phpredis extension.
        return new Collection([
            'App' => App::class,
            'Arr' => Arr::class,
            'Artisan' => Artisan::class,
            'Auth' => Auth::class,
            'Benchmark' => Benchmark::class,
            'Blade' => Blade::class,
            'Broadcast' => Broadcast::class,
            'Bus' => Bus::class,
            'Cache' => Cache::class,
            'Concurrency' => Concurrency::class,
            'Config' => Config::class,
            'Context' => Context::class,
            'Cookie' => Cookie::class,
            'Crypt' => Crypt::class,
            'Date' => Date::class,
            'DB' => DB::class,
            'Eloquent' => Model::class,
            'Event' => Event::class,
            'Exceptions' => Exceptions::class,
            'File' => File::class,
            'Gate' => Gate::class,
            'Hash' => Hash::class,
            'Http' => Http::class,
            'Js' => Js::class,
            'JWT' => JWT::class,
            'Lang' => Lang::class,
            'Log' => Log::class,
            'Mail' => Mail::class,
            'Notification' => Notification::class,
            'Number' => Number::class,
            'Password' => Password::class,
            'Process' => Process::class,
            'Queue' => Queue::class,
            'RateLimiter' => RateLimiter::class,
            'Redirect' => Redirect::class,
            'Request' => Request::class,
            'Response' => Response::class,
            'Route' => Route::class,
            'Schema' => Schema::class,
            'Schedule' => Schedule::class,
            'Session' => Session::class,
            'Storage' => Storage::class,
            'Str' => Str::class,
            'Uri' => Uri::class,
            'URL' => URL::class,
            'Validator' => Validator::class,
            'View' => View::class,
            'Vite' => Vite::class,
        ]);
    }

    /**
     * Get the application instance behind the facade.
     */
    public static function getFacadeApplication()
    {
        return static::$app;
    }

    /**
     * Set the application instance.
     * @param mixed $app
     */
    public static function setFacadeApplication($app): void
    {
        static::$app = $app;
    }

    /**
     * Handle dynamic, static calls to the object.
     *
     * @throws RuntimeException
     */
    public static function __callStatic(string $method, array $args)
    {
        $instance = static::getFacadeRoot();

        if (! $instance) {
            throw new RuntimeException('A facade root has not been set.');
        }

        return $instance->{$method}(...$args);
    }
}
