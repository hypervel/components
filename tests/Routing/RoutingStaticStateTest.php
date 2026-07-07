<?php

declare(strict_types=1);

namespace Hypervel\Tests\Routing\RoutingStaticStateTest;

use Hypervel\Routing\Middleware\ThrottleRequests;
use Hypervel\Routing\PendingResourceRegistration;
use Hypervel\Routing\PendingSingletonResourceRegistration;
use Hypervel\Routing\Redirector;
use Hypervel\Routing\ResponseFactory;
use Hypervel\Routing\Route;
use Hypervel\Routing\Router;
use Hypervel\Routing\RouteRegistrar;
use Hypervel\Routing\UrlGenerator;
use Hypervel\Tests\Routing\RoutingTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;

class RoutingStaticStateTest extends RoutingTestCase
{
    /**
     * @param class-string $class
     */
    #[DataProvider('macroableRoutingClasses')]
    public function testFlushStateClearsRoutingMacros(string $class): void
    {
        $class::macro('routingFlushProbe', static fn (): string => 'ok');

        $this->assertTrue($class::hasMacro('routingFlushProbe'));

        $class::flushState();

        $this->assertFalse($class::hasMacro('routingFlushProbe'));
    }

    public static function macroableRoutingClasses(): array
    {
        return [
            [PendingResourceRegistration::class],
            [PendingSingletonResourceRegistration::class],
            [Redirector::class],
            [ResponseFactory::class],
            [RouteRegistrar::class],
            [Router::class],
            [UrlGenerator::class],
        ];
    }

    public function testRouteFlushStateClearsEnumCache(): void
    {
        $enumCache = new ReflectionProperty(Route::class, 'enumCache');
        $enumCache->setValue(null, ['Some\Enum' => true]);

        $this->assertSame(['Some\Enum' => true], $enumCache->getValue());

        Route::flushState();

        $this->assertSame([], $enumCache->getValue());
    }

    public function testThrottleRequestsFlushStateRestoresHashedKeys(): void
    {
        $shouldHashKeys = new ReflectionProperty(ThrottleRequests::class, 'shouldHashKeys');

        ThrottleRequests::shouldHashKeys(false);

        $this->assertFalse($shouldHashKeys->getValue());

        ThrottleRequests::flushState();

        $this->assertTrue($shouldHashKeys->getValue());
    }
}
