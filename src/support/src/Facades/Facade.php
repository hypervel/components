<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Closure;
use Hypervel\Context\ApplicationContext;
use Hypervel\Support\Collection;
use Hypervel\Support\Testing\Fakes\Fake;
use Mockery;
use Mockery\LegacyMockInterface;
use RuntimeException;

abstract class Facade
{
    /**
     * The resolved object instances.
     */
    protected static array $resolvedInstance;

    /**
     * Run a Closure when the facade has been resolved.
     */
    public static function resolved(Closure $callback): void
    {
        $container = ApplicationContext::getContainer();
        $accessor = static::getFacadeAccessor();

        if ($container->resolved($accessor) === true) {
            $callback(static::getFacadeRoot());
        }

        $container->afterResolving($accessor, function ($service) use ($callback) {
            $callback($service);
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

        ApplicationContext::getContainer()->instance(static::getFacadeAccessor(), $instance);
    }

    /**
     * Handle dynamic, static calls to the object.
     *
     * @return mixed
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
     * Resolve the facade root instance from the container.
     */
    protected static function resolveFacadeInstance(object|string $name): mixed
    {
        if (is_object($name)) {
            return $name;
        }

        if (isset(static::$resolvedInstance[$name])) {
            return static::$resolvedInstance[$name];
        }

        if (ApplicationContext::getContainer()->has($name)) {
            return static::$resolvedInstance[$name] = ApplicationContext::getContainer()->get($name);
        }

        return null;
    }

    /**
     * Clear a resolved facade instance.
     */
    public static function clearResolvedInstance(string $name): void
    {
        unset(static::$resolvedInstance[$name]);
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
        return new Collection([
            'App' => App::class,
            'Artisan' => Artisan::class,
            'Auth' => Auth::class,
            'Blade' => Blade::class,
            'Broadcast' => Broadcast::class,
            'Bus' => Bus::class,
            'Cache' => Cache::class,
            'Config' => Config::class,
            'Cookie' => Cookie::class,
            'Crypt' => Crypt::class,
            'Date' => Date::class,
            'DB' => DB::class,
            'Environment' => Environment::class,
            'Event' => Event::class,
            'Exceptions' => Exceptions::class,
            'File' => File::class,
            'Gate' => Gate::class,
            'Hash' => Hash::class,
            'Http' => Http::class,
            'JWT' => JWT::class,
            'Lang' => Lang::class,
            'Log' => Log::class,
            'Mail' => Mail::class,
            'Notification' => Notification::class,
            'Process' => Process::class,
            'Queue' => Queue::class,
            'RateLimiter' => RateLimiter::class,
            'Redis' => Redis::class,
            'Request' => Request::class,
            'Response' => Response::class,
            'Route' => Route::class,
            'Schedule' => Schedule::class,
            'Session' => Session::class,
            'Storage' => Storage::class,
            'URL' => URL::class,
            'Validator' => Validator::class,
            'View' => View::class,
        ]);
    }

    /**
     * Get the registered name of the component.
     *
     * @return object|string
     */
    protected static function getFacadeAccessor()
    {
        throw new RuntimeException('Facade does not implement getFacadeAccessor method.');
    }
}
