<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\Events\PasswordResetLinkSent;
use Hypervel\Auth\Passwords\PasswordBroker;
use Hypervel\Auth\Passwords\TokenRepositoryInterface;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\CanResetPassword;
use Hypervel\Contracts\Auth\PasswordBroker as PasswordBrokerContract;
use Hypervel\Contracts\Auth\UserProvider;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Foundation\Auth\User;
use Hypervel\Support\Arr;
use Hypervel\Support\Sleep;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use Override;
use UnexpectedValueException;

class AuthPasswordBrokerTest extends TestCase
{
    use VerifiesDoubles;

    /**
     * Set up the test environment.
     */
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }

    public function testIfUserIsNotFoundErrorRedirectIsReturned(): void
    {
        $broker = $this->getBroker($mocks = $this->getMocks());
        $mocks['users']->expects('retrieveByCredentials')->with(['credentials'])->returns(null);

        $this->assertSame(PasswordBrokerContract::INVALID_USER, $broker->sendResetLink(['credentials']));
    }

    public function testIfTokenIsRecentlyCreated(): void
    {
        $broker = $this->getBroker($mocks = $this->getMocks());
        $user = new User;
        $mocks['users']->expects('retrieveByCredentials')->with(['foo'])->returns($user);
        $mocks['tokens']->expects('recentlyCreatedToken')->with($user)->returns(true);

        $this->assertSame(PasswordBrokerContract::RESET_THROTTLED, $broker->sendResetLink(['foo']));
    }

    public function testGetUserThrowsExceptionIfUserDoesntImplementCanResetPassword(): void
    {
        $this->expectExceptionObject(new UnexpectedValueException('User must implement CanResetPassword interface.'));

        $broker = $this->getBroker($mocks = $this->getMocks());
        $mocks['users']->expects('retrieveByCredentials')->with(['foo'])->returns(Double::for(Authenticatable::class));

        $broker->getUser(['foo']);
    }

    public function testUserIsRetrievedByCredentials(): void
    {
        $broker = $this->getBroker($mocks = $this->getMocks());
        $user = new User;
        $mocks['users']->expects('retrieveByCredentials')->with(['foo'])->returns($user);

        $this->assertEquals($user, $broker->getUser(['foo']));
    }

    public function testBrokerCreatesTokenAndRedirectsWithoutError(): void
    {
        $broker = $this->getBroker($mocks = $this->getMocks());
        $user = Double::for(Authenticatable::class, CanResetPassword::class);
        $mocks['users']->expects('retrieveByCredentials')->with(['foo'])->returns($user);
        $mocks['tokens']->expects('recentlyCreatedToken')->with($user)->returns(false);
        $mocks['tokens']->expects('create')->with($user)->returns('token');
        $user->expects('sendPasswordResetNotification')->with('token');

        $this->assertSame(PasswordBrokerContract::RESET_LINK_SENT, $broker->sendResetLink(['foo']));
    }

    public function testResetLinkEventIsSkippedWhenThereAreNoListeners(): void
    {
        $mocks = $this->getMocks();
        $events = Double::for(Dispatcher::class);
        $events->expects('hasListeners')->with(PasswordResetLinkSent::class)->returns(false);
        $events->expects('dispatch')->never();
        $mocks['users']->expects('retrieveByCredentials')->with(['foo'])->returns($user = Double::for(Authenticatable::class, CanResetPassword::class));
        $mocks['tokens']->expects('recentlyCreatedToken')->with($user)->returns(false);
        $mocks['tokens']->expects('create')->with($user)->returns('token');
        $user->expects('sendPasswordResetNotification')->with('token');
        $broker = new PasswordBroker($mocks['tokens'], $mocks['users'], $mocks['name'], $events);

        $this->assertSame(PasswordBrokerContract::RESET_LINK_SENT, $broker->sendResetLink(['foo']));
    }

    public function testResetLinkEventIsDispatchedWhenThereIsAListener(): void
    {
        $mocks = $this->getMocks();
        $events = Double::for(Dispatcher::class);
        $events->expects('hasListeners')->with(PasswordResetLinkSent::class)->returns(true);
        $events->expects('dispatch')
            ->with(Argument::type(PasswordResetLinkSent::class));
        $mocks['users']->expects('retrieveByCredentials')->with(['foo'])->returns($user = Double::for(Authenticatable::class, CanResetPassword::class));
        $mocks['tokens']->expects('recentlyCreatedToken')->with($user)->returns(false);
        $mocks['tokens']->expects('create')->with($user)->returns('token');
        $user->expects('sendPasswordResetNotification')->with('token');
        $broker = new PasswordBroker($mocks['tokens'], $mocks['users'], $mocks['name'], $events);

        $this->assertSame(PasswordBrokerContract::RESET_LINK_SENT, $broker->sendResetLink(['foo']));
    }

    public function testEventDispatcherCanBeReplacedOnAnExistingBroker(): void
    {
        $mocks = $this->getMocks();
        $originalEvents = Double::for(Dispatcher::class);
        $originalEvents->expects('hasListeners')->never();
        $originalEvents->expects('dispatch')->never();
        $replacementEvents = Double::for(Dispatcher::class);
        $replacementEvents->expects('hasListeners')->with(PasswordResetLinkSent::class)->returns(true);
        $replacementEvents->expects('dispatch')
            ->with(Argument::type(PasswordResetLinkSent::class));
        $mocks['users']->expects('retrieveByCredentials')->with(['foo'])->returns($user = Double::for(Authenticatable::class, CanResetPassword::class));
        $mocks['tokens']->expects('recentlyCreatedToken')->with($user)->returns(false);
        $mocks['tokens']->expects('create')->with($user)->returns('token');
        $user->expects('sendPasswordResetNotification')->with('token');
        $broker = new PasswordBroker($mocks['tokens'], $mocks['users'], $mocks['name'], $originalEvents);

        $broker->setDispatcher($replacementEvents);

        $this->assertSame(PasswordBrokerContract::RESET_LINK_SENT, $broker->sendResetLink(['foo']));
    }

    public function testRedirectIsReturnedByResetWhenUserCredentialsInvalid(): void
    {
        $broker = $this->getBroker($mocks = $this->getMocks());
        $mocks['users']->expects('retrieveByCredentials')->with(['creds'])->returns(null);

        $this->assertSame(PasswordBrokerContract::INVALID_USER, $broker->reset(['creds'], function (): void {
        }));
    }

    public function testRedirectReturnedByRemindWhenRecordDoesntExistInTable(): void
    {
        $credentials = ['token' => 'token'];
        $broker = $this->getBroker($mocks = $this->getMocks());
        $user = new User;
        $mocks['users']->expects('retrieveByCredentials')->with(Arr::except($credentials, ['token']))->returns($user);
        $mocks['tokens']->expects('exists')->with($user, 'token')->returns(false);

        $this->assertSame(PasswordBrokerContract::INVALID_TOKEN, $broker->reset($credentials, function (): void {
        }));
    }

    public function testResetRemovesRecordOnReminderTableAndCallsCallback(): void
    {
        $resetArguments = null;
        $broker = $this->getBroker($mocks = $this->getMocks());
        $user = new User;
        $mocks['users']->expects('retrieveByCredentials')->with(['password' => 'password'])->returns($user);
        $mocks['tokens']->expects('exists')->with($user, 'token')->returns(true);
        $mocks['tokens']->expects('delete')->with($user);
        $callback = function (CanResetPassword $user, string $password) use (&$resetArguments): string {
            $resetArguments = ['user' => $user, 'password' => $password];

            return 'foo';
        };

        $this->assertSame(PasswordBrokerContract::PASSWORD_RESET, $broker->reset(['password' => 'password', 'token' => 'token'], $callback));
        $this->assertEquals(['user' => $user, 'password' => 'password'], $resetArguments);
    }

    public function testExecutesCallbackInsteadOfSendingNotification(): void
    {
        $executed = false;

        $closure = function () use (&$executed): void {
            $executed = true;
        };

        $broker = $this->getBroker($mocks = $this->getMocks());
        $user = new User;
        $mocks['users']->expects('retrieveByCredentials')->with(['foo'])->returns($user);
        $mocks['tokens']->expects('recentlyCreatedToken')->with($user)->returns(false);
        $mocks['tokens']->expects('create')->with($user)->returns('token');

        $this->assertEquals(PasswordBrokerContract::RESET_LINK_SENT, $broker->sendResetLink(['foo'], $closure));

        $this->assertTrue($executed);
    }

    public function testSendResetLinkStampsSendingBrokerContext(): void
    {
        $broker = $this->getBroker($mocks = $this->getMocks());
        $mocks['users']->expects('retrieveByCredentials')->with(['foo'])->returns($user = Double::for(Authenticatable::class, CanResetPassword::class));
        $mocks['tokens']->expects('recentlyCreatedToken')->with($user)->returns(false);
        $mocks['tokens']->expects('create')->with($user)->returns('token');
        $user->expects('sendPasswordResetNotification')
            ->with('token')
            ->resolves(function (): void {
                $this->assertSame('users', CoroutineContext::get(PasswordBroker::SENDING_BROKER_CONTEXT_KEY));
            });

        $this->assertSame(PasswordBrokerContract::RESET_LINK_SENT, $broker->sendResetLink(['foo']));
    }

    public function testSendResetLinkRestoresPreviousSendingBrokerContext(): void
    {
        CoroutineContext::set(PasswordBroker::SENDING_BROKER_CONTEXT_KEY, 'outer');

        $broker = $this->getBroker($mocks = $this->getMocks());
        $mocks['users']->expects('retrieveByCredentials')->with(['foo'])->returns($user = Double::for(Authenticatable::class, CanResetPassword::class));
        $mocks['tokens']->expects('recentlyCreatedToken')->with($user)->returns(false);
        $mocks['tokens']->expects('create')->with($user)->returns('token');
        $user->expects('sendPasswordResetNotification')->with('token');

        $this->assertSame(PasswordBrokerContract::RESET_LINK_SENT, $broker->sendResetLink(['foo']));
        $this->assertSame('outer', CoroutineContext::get(PasswordBroker::SENDING_BROKER_CONTEXT_KEY));
    }

    public function testSendResetLinkForgetsSendingBrokerContextWhenNoneExisted(): void
    {
        CoroutineContext::forget(PasswordBroker::SENDING_BROKER_CONTEXT_KEY);

        $broker = $this->getBroker($mocks = $this->getMocks());
        $mocks['users']->expects('retrieveByCredentials')->with(['foo'])->returns($user = Double::for(Authenticatable::class, CanResetPassword::class));
        $mocks['tokens']->expects('recentlyCreatedToken')->with($user)->returns(false);
        $mocks['tokens']->expects('create')->with($user)->returns('token');
        $user->expects('sendPasswordResetNotification')->with('token');

        $this->assertSame(PasswordBrokerContract::RESET_LINK_SENT, $broker->sendResetLink(['foo']));
        $this->assertFalse(CoroutineContext::has(PasswordBroker::SENDING_BROKER_CONTEXT_KEY));
    }

    public function testSendResetLinkStampsContextForCallback(): void
    {
        $broker = $this->getBroker($mocks = $this->getMocks());
        $mocks['users']->expects('retrieveByCredentials')->with(['foo'])->returns($user = Double::for(Authenticatable::class, CanResetPassword::class));
        $mocks['tokens']->expects('recentlyCreatedToken')->with($user)->returns(false);
        $mocks['tokens']->expects('create')->with($user)->returns('token');
        $user->expects('sendPasswordResetNotification')->never();

        $this->assertSame(PasswordBrokerContract::RESET_LINK_SENT, $broker->sendResetLink(['foo'], function (): void {
            $this->assertSame('users', CoroutineContext::get(PasswordBroker::SENDING_BROKER_CONTEXT_KEY));
        }));
    }

    /**
     * Create a broker with the given dependencies.
     */
    protected function getBroker(array $mocks): PasswordBroker
    {
        return new PasswordBroker($mocks['tokens'], $mocks['users'], $mocks['name']);
    }

    /**
     * Create the broker's dependencies.
     */
    protected function getMocks(): array
    {
        return [
            'tokens' => Double::for(TokenRepositoryInterface::class),
            'users' => Double::for(UserProvider::class),
            'name' => 'users',
        ];
    }
}
