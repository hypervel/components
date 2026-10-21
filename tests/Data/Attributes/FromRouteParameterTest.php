<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Attributes\FromRouteParameterTest;

use Hypervel\Container\Attributes\RouteParameter;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Data;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Data\Fixtures\Concerns\BindsRouteParameters;

class FromRouteParameterTest extends TestCase
{
    use BindsRouteParameters;

    // Spatie's FromRouteParameter is not included; Hypervel's RouteParameter contextual attribute reads the current route.

    /**
     * Get package providers for the test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    public function testItCanGetARouteParameter(): void
    {
        $request = $this->bindRouteParameters(['parameter' => 'test']);

        $dataClass = new class('') extends Data {
            /**
             * Create the data object from a route parameter.
             */
            public function __construct(
                #[RouteParameter('parameter')]
                public string $property,
            ) {
            }
        };

        $this->assertSame('test', $dataClass::from($request)->property);
    }

    public function testReadsTheCurrentRouteWhenThePayloadIsNotARequest(): void
    {
        // Upstream's 'wont replace a route parameter if the payload is not a request': it reads the route only from a
        // request payload, while a contextual value comes from the current request and wins.
        $this->bindRouteParameters(['parameter' => 'route']);

        $dataClass = new class('') extends Data {
            /**
             * Create the data object from a route parameter.
             */
            public function __construct(
                #[RouteParameter('parameter')]
                public string $property,
            ) {
            }
        };

        $this->assertSame('route', $dataClass::from(['property' => 'payload'])->property);
    }
}
