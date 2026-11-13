<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\RequestDependentBindings;

use Hypervel\Container\Attributes\BindWhen;
use Hypervel\Container\Container as ContainerImplementation;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Auth\Guard;
use Hypervel\Contracts\Container\Container;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\Auth;
use Hypervel\Support\Facades\Request as RequestFacade;

class Implementation
{
}

#[BindWhen(Implementation::class, static fn (): bool => request()->is('api/*'))]
interface UsesRequestHelper
{
}

#[BindWhen(Implementation::class, static fn (): bool => RequestFacade::is('api/*'))]
interface UsesRequestFacade
{
}

#[BindWhen(Implementation::class, static fn (): bool => RequestContext::has())]
interface UsesRequestContext
{
}

#[BindWhen(Implementation::class, static function (): bool {
    $driver = Auth::getDefaultDriver();

    return Auth::check();
})]
interface UsesAuthFacade
{
}

#[BindWhen(Implementation::class, static fn (): bool => auth()->guard('web')->user() !== null)]
interface UsesGuard
{
}

#[BindWhen(Implementation::class, static fn (Container $app): bool => $app->make(Request::class)->is('api/*'))]
interface ResolvesRequestAlias
{
}

#[BindWhen(condition: static fn (): bool => app('request')->is('api/*'), concrete: Implementation::class)]
interface ResolvesRequestByName
{
}

#[BindWhen(Implementation::class, Conditions::onApi(...))]
interface UsesConditionMethod
{
}

#[BindWhen(Implementation::class, onAdminPath(...))]
interface UsesConditionFunction
{
}

#[BindWhen(Implementation::class, static fn (): bool => Auth::getDefaultDriver() === 'web')]
interface ReadsAuthConfiguration
{
}

#[BindWhen(Implementation::class, static fn (Container $app): bool => $app->make('config')->get('app.env') === 'production')]
interface ReadsConfiguration
{
}

#[BindWhen(Implementation::class, Conditions::inProduction(...))]
interface UsesBootStableMethod
{
}

#[BindWhen(Implementation::class, static fn (): bool => RequestFacade::hasMacro('feature'))]
interface ReadsRequestMacros
{
}

#[BindWhen(Implementation::class, static fn (): bool => BootRequestContext::has() || BootAuth::check() || BootAuth::guard()->check())]
interface UsesOverridingReaders
{
}

#[BindWhen(Implementation::class, static function (Container $app): bool {
    $app = new ContainerImplementation;

    return $app->make('request')->is('api/*');
})]
interface ReassignsContainer
{
}

#[BindWhen(Implementation::class, LocalRequest\onLocalFlag(...))]
interface UsesLocalFunctionInAnotherNamespace
{
}

#[BindWhen(Implementation::class, static fn (): bool => array_any(['api/*', 'v2/*'], static fn (string $pattern): bool => request()->is($pattern)))]
interface ReadsInCallback
{
}

#[BindWhen(Implementation::class, static function (Container $app): bool {
    $reset = static function (): bool {
        $app = null;

        return $app === null;
    };

    return $reset() && array_any(['api/*'], static function (string $pattern) use ($app): bool {
        return $app->make('request')->is($pattern);
    });
})]
interface CapturesContainer
{
}

#[BindWhen(Implementation::class, static fn (Container $app): bool => array_any(
    [new ContainerImplementation],
    static fn (ContainerImplementation $app): bool => $app->make('request')->is('api/*'),
))]
interface ShadowsContainer
{
}

#[BindWhen(Implementation::class, static function (Container $app): bool {
    return array_any(['api/*'], static function (string $pattern): bool {
        return $app->make('request')->is($pattern);
    });
})]
interface LeavesContainerUncaptured
{
}

class BootRequestContext extends RequestContext
{
    public static function has(?int $coroutineId = null): bool
    {
        return false;
    }
}

class BootAuth extends Auth
{
    public static function check(): bool
    {
        return false;
    }

    public static function guard(): Guard
    {
        return Auth::guard('boot');
    }
}

class Conditions
{
    public static function onApi(Container $container): bool
    {
        return $container->make('request')->is('api/*');
    }

    public static function inProduction(Container $container): bool
    {
        return $container->make('config')->get('app.env') === 'production';
    }
}

function onAdminPath(): bool
{
    return request()->is('admin/*');
}
