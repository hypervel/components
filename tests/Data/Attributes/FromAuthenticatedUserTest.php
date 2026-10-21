<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Attributes\FromAuthenticatedUserTest;

use Hypervel\Container\Attributes\CurrentUser;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Data;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Foundation\Auth\User;
use Hypervel\Testbench\TestCase;

class FromAuthenticatedUserTest extends TestCase
{
    // Spatie's FromAuthenticatedUser is not included; Hypervel's CurrentUser contextual attribute supplies the user.

    /**
     * Get package providers for the test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    /**
     * Define the additional guard used by the guard test.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('auth.guards.other', ['driver' => 'session', 'provider' => 'users']);
    }

    public function testCanGetTheCurrentLoggedInUser(): void
    {
        $this->actingAs($user = new User);

        $dataClass = new class(null) extends Data {
            /**
             * Create the data object from the current user.
             */
            public function __construct(
                #[CurrentUser]
                public ?Authenticatable $user,
            ) {
            }
        };

        $this->assertSame($user, $dataClass::from()->user);
    }

    public function testCanGetTheCurrentLoggedInUserUsingAnotherGuard(): void
    {
        $this->app->make('auth')->guard('other')->setUser($user = new User);

        $defaultGuard = new class(null) extends Data {
            /**
             * Create the data object from the default guard's user.
             */
            public function __construct(
                #[CurrentUser]
                public ?Authenticatable $user,
            ) {
            }
        };

        $this->assertNull($defaultGuard::from()->user);

        $otherGuard = new class(null) extends Data {
            /**
             * Create the data object from the other guard's user.
             */
            public function __construct(
                #[CurrentUser('other')]
                public ?Authenticatable $user,
            ) {
            }
        };

        $this->assertSame($user, $otherGuard::from()->user);
    }

    public function testWillNotSetTheUserPropertyWhenNotLoggedIn(): void
    {
        // Upstream leaves the property unset; a contextual value always wins, so a guest is null.
        $dataClass = new class(null) extends Data {
            /**
             * Create the data object from the current user.
             */
            public function __construct(
                #[CurrentUser]
                public ?Authenticatable $user,
            ) {
            }
        };

        $this->assertNull($dataClass::from()->user);
    }
}
