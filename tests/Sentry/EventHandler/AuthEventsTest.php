<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\EventHandler;

use Hypervel\Auth\Authenticatable;
use Hypervel\Auth\Events\Authenticated;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Http\Request;
use Hypervel\Tests\Sentry\TestCase;
use Swoole\Coroutine\CanceledException;

class AuthEventsTest extends TestCase
{
    protected array $setupConfig = [
        'sentry.send_default_pii' => true,
    ];

    public function testAuthenticatedEventFillsUserOnScope(): void
    {
        $user = new AuthEventsTestUserModel;

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
        $this->assertNull($scope->getUser()->getIpAddress());
    }

    public function testAuthenticatedEventInAChildCoroutineUsesTheInheritedRequestAddress(): void
    {
        RequestContext::set(Request::create('/', server: ['REMOTE_ADDR' => '203.0.113.10']));
        $user = new AuthEventsTestUserModel;
        $user->forceFill(['id' => 123]);
        $ipAddress = null;

        Coroutine::create(function () use ($user, &$ipAddress): void {
            $this->dispatchHypervelEvent(new Authenticated('test', $user));
            $ipAddress = $this->getCurrentSentryScope()->getUser()?->getIpAddress();
        });

        $this->assertSame('203.0.113.10', $ipAddress);
    }

    public function testAuthenticatedEventPropagatesCancellationFromUserAccessor(): void
    {
        $cancellation = new CanceledException('Request canceled');
        $user = new class extends AuthEventsTestUserModel {
            /**
             * Simulate cancellation during an email accessor's I/O.
             */
            public function getEmailAttribute(CanceledException $value): never
            {
                throw $value;
            }
        };
        $user->forceFill(['email' => $cancellation]);

        try {
            $this->dispatchHypervelEvent(new Authenticated('test', $user));
            $this->fail('The event handler swallowed coroutine cancellation.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    public function testAuthenticatedEventFillsUserOnScopeWhenUsernameIsNotAString(): void
    {
        $user = new AuthEventsTestUserModel;

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
        $user = new AuthEventsTestUserModel;

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
        $user = new AuthEventsTestUserModel;

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
        $user = new AuthEventsTestUserModel;

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

    public function testAuthenticatedEventPreservesFalseyUserFields(): void
    {
        $user = new AuthEventsTestUserModel;

        $user->forceFill([
            'id' => 0,
            'username' => '',
            'email' => '',
        ]);

        $this->dispatchHypervelEvent(new Authenticated('test', $user));

        $sentryUser = $this->getCurrentSentryScope()->getUser();
        $this->assertNotNull($sentryUser);
        $this->assertSame(0, $sentryUser->getId());
        $this->assertSame('', $sentryUser->getUsername());
        $this->assertSame('', $sentryUser->getEmail());
    }

    public function testAuthenticatedEventDoesNotFillUserOnScopeWhenPIIShouldNotBeSent(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => false,
        ]);

        $user = new AuthEventsTestUserModel;

        $user->id = 123;

        $scope = $this->getCurrentSentryScope();

        $this->assertNull($scope->getUser());

        $this->dispatchHypervelEvent(new Authenticated('test', $user));

        $this->assertNull($scope->getUser());
    }

    public function testAuthenticatedEventFillsUserOnScopeWithDataCollectionDefaults(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => false,
            'sentry.data_collection' => [],
        ]);

        $user = new AuthEventsTestUserModel;

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

    public function testAuthenticatedEventDoesNotFillUserOnScopeWhenUserInfoIsDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => true,
            'sentry.data_collection' => [
                'user_info' => false,
            ],
        ]);

        $user = new AuthEventsTestUserModel;

        $user->id = 123;

        $scope = $this->getCurrentSentryScope();

        $this->assertNull($scope->getUser());

        $this->dispatchHypervelEvent(new Authenticated('test', $user));

        $this->assertNull($scope->getUser());
    }
}

class AuthEventsTestUserModel extends Model implements AuthenticatableContract
{
    use Authenticatable;
}
