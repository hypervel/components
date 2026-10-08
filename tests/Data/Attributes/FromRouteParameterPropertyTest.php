<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Attributes\FromRouteParameterPropertyTest;

use Hypervel\Container\Attributes\RouteParameter;
use Hypervel\Contracts\Container\BindingResolutionException;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Data;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Data\Fixtures\Concerns\BindsRouteParameters;

class FromRouteParameterPropertyTest extends TestCase
{
    use BindsRouteParameters;

    // Spatie's FromRouteParameterProperty is not included; RouteParameter takes an explicit property path.
    // REMOVED: 'can get a route parameter property value based upon the property name'; the path is never inferred from the parameter name.

    /**
     * Get package providers for the test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    public function testCanGetAUserPropertyValueBasedUponAKeyDefinedInTheAttribute(): void
    {
        $request = $this->bindRouteParameters(['parameter' => ['test' => 'Hello World']]);

        $dataClass = new class('') extends Data {
            /**
             * Create the data object from a route parameter property.
             */
            public function __construct(
                #[RouteParameter('parameter', property: 'test')]
                public string $property,
            ) {
            }
        };

        $this->assertSame('Hello World', $dataClass::from($request)->property);
    }

    public function testThrowsAnExceptionWhenTryingToFillARouteParameterPropertyUsingAScalarValue(): void
    {
        $request = $this->bindRouteParameters(['parameter' => 'not-valid']);

        $dataClass = new class('') extends Data {
            /**
             * Create the data object from a route parameter property.
             */
            public function __construct(
                #[RouteParameter('parameter', property: 'test')]
                public string $property,
            ) {
            }
        };

        $this->expectException(BindingResolutionException::class);
        $this->expectExceptionMessageIsOrContains('Cannot extract property path [test] from scalar [string]');

        $dataClass::from($request);
    }
}
