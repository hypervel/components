<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Attributes\FromAuthenticatedUserPropertyTest;

use Hypervel\Container\Attributes\CurrentUser;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Data;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Foundation\Auth\User;
use Hypervel\Testbench\TestCase;

class FromAuthenticatedUserPropertyTest extends TestCase
{
    // Spatie's FromAuthenticatedUserProperty is not included; CurrentUser takes an explicit property path.
    // REMOVED: 'can get a user property value based upon the property name'; the path is never inferred from the parameter name.

    /**
     * Get package providers for the test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    public function testCanGetAUserPropertyValueBasedUponAKeyDefinedInTheAttribute(): void
    {
        $this->actingAs((new User)->forceFill(['property' => 'value']));

        $dataClass = new class('') extends Data {
            /**
             * Create the data object from a property of the current user.
             */
            public function __construct(
                #[CurrentUser(property: 'property')]
                public string $value,
            ) {
            }
        };

        $this->assertSame('value', $dataClass::from()->value);
    }
}
