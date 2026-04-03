<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\EventHandler;

use Hypervel\Auth\Events\Authenticated;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Tests\Sentry\SentryTestCase;

/**
 * @internal
 * @coversNothing
 */
class AuthEventsTest extends SentryTestCase
{
    protected array $setupConfig = [
        'sentry.send_default_pii' => true,
    ];

    public function testAuthenticatedEventFillsUserOnScope(): void
    {
        $user = new AuthEventsTestUserModel();

        $user->forceFill([
            'id' => 123,
            'username' => 'username',
            'email' => 'foo@example.com',
        ]);

        $scope = $this->getCurrentSentryScope();

        $this->assertNull($scope->getUser());

        $this->dispatchHypervelEvent(new Authenticated('test', $user));

        $this->assertNotNull($scope->getUser());

        $this->assertEquals(123, $scope->getUser()->getId());
        $this->assertEquals('username', $scope->getUser()->getUsername());
        $this->assertEquals('foo@example.com', $scope->getUser()->getEmail());
    }

    public function testAuthenticatedEventFillsUserOnScopeWhenUsernameIsNotAString(): void
    {
        $user = new AuthEventsTestUserModel();

        $user->forceFill([
            'id' => 123,
            'username' => 456,
        ]);

        $scope = $this->getCurrentSentryScope();

        $this->assertNull($scope->getUser());

        $this->dispatchHypervelEvent(new Authenticated('test', $user));

        $this->assertNotNull($scope->getUser());

        $this->assertEquals(123, $scope->getUser()->getId());
        $this->assertEquals('456', $scope->getUser()->getUsername());
    }

    public function testAuthenticatedEventFillsUserOnScopeWhenEmailIsNotAString(): void
    {
        $user = new AuthEventsTestUserModel();

        $user->forceFill([
            'id' => 123,
            'email' => 456,
        ]);

        $scope = $this->getCurrentSentryScope();

        $this->assertNull($scope->getUser());

        $this->dispatchHypervelEvent(new Authenticated('test', $user));

        $this->assertNotNull($scope->getUser());

        $this->assertEquals(123, $scope->getUser()->getId());
        $this->assertEquals('456', $scope->getUser()->getEmail());
    }

    public function testAuthenticatedEventFillsUserOnScopeWhenEmailCanBeCastToAString(): void
    {
        $user = new AuthEventsTestUserModel();

        $user->forceFill([
            'id' => 123,
            'email' => new class {
                public function __toString(): string
                {
                    return 'foo@example.com';
                }
            },
        ]);

        $scope = $this->getCurrentSentryScope();

        $this->assertNull($scope->getUser());

        $this->dispatchHypervelEvent(new Authenticated('test', $user));

        $this->assertNotNull($scope->getUser());

        $this->assertEquals(123, $scope->getUser()->getId());
        $this->assertEquals('foo@example.com', $scope->getUser()->getEmail());
    }

    public function testAuthenticatedEventDoesNotSetEmailOnScopeWhenEmailAttributeIsNull(): void
    {
        $user = new AuthEventsTestUserModel();

        $user->forceFill([
            'id' => 123,
            'email' => null,
        ]);

        $scope = $this->getCurrentSentryScope();

        $this->assertNull($scope->getUser());

        $this->dispatchHypervelEvent(new Authenticated('test', $user));

        $this->assertNotNull($scope->getUser());

        $this->assertEquals(123, $scope->getUser()->getId());
        $this->assertNull($scope->getUser()->getEmail());
    }

    public function testAuthenticatedEventDoesNotFillUserOnScopeWhenPIIShouldNotBeSent(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => false,
        ]);

        $user = new AuthEventsTestUserModel();

        $user->id = 123;

        $scope = $this->getCurrentSentryScope();

        $this->assertNull($scope->getUser());

        $this->dispatchHypervelEvent(new Authenticated('test', $user));

        $this->assertNull($scope->getUser());
    }
}

class AuthEventsTestUserModel extends Model implements Authenticatable
{
    use \Hypervel\Auth\Authenticatable;
}
