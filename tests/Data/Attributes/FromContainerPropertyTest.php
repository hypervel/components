<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Attributes\FromContainerPropertyTest;

use Hypervel\Container\Attributes\Give;
use Hypervel\Contracts\Container\BindingResolutionException;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Data;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Testbench\TestCase;

class FromContainerPropertyTest extends TestCase
{
    // Spatie's FromContainerProperty is not included; Give takes an explicit property path.
    // REMOVED: 'can get a container property value based upon the property name'; the path is never inferred from the parameter name.

    /**
     * Get package providers for the test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    public function testCanGetAUserPropertyValueBasedUponAKeyDefinedInTheAttribute(): void
    {
        $this->app->bind('test', fn (): array => [
            'property' => 'defined',
        ]);

        $dataClass = new class('') extends Data {
            /**
             * Create the data object from a container entry property.
             */
            public function __construct(
                #[Give('test', property: 'property')]
                public string $value,
            ) {
            }
        };

        $this->assertSame('defined', $dataClass::from()->value);
    }

    public function testThrowsAnExceptionWhenTryingToFillADependencyPropertyUsingAScalarValue(): void
    {
        $this->app->bind('test', fn (): string => 'not-valid');

        $dataClass = new class('') extends Data {
            /**
             * Create the data object from a container entry property.
             */
            public function __construct(
                #[Give('test', property: 'property')]
                public string $property,
            ) {
            }
        };

        $this->expectException(BindingResolutionException::class);
        $this->expectExceptionMessageIsOrContains('Cannot extract property path [property] from scalar [string]');

        $dataClass::from();
    }
}
