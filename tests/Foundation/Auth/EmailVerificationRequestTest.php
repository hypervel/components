<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Auth;

use Hypervel\Auth\Events\Verified;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\MustVerifyEmail;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Foundation\Auth\EmailVerificationRequest;
use Hypervel\Foundation\Auth\User;
use Hypervel\Routing\Router;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use Override;

class EmailVerificationRequestTest extends TestCase
{
    use VerifiesDoubles;

    #[Override]
    protected function defineRoutes(Router $router): void
    {
        $router->get('email/verify/{id}/{hash}', function (EmailVerificationRequest $request) {
            $request->fulfill();

            return 'verified';
        })->name('verification.verify');
    }

    public function testAuthorizeReturnsTrueWhenIdAndHashMatch(): void
    {
        $user = $this->mockUser(123, 'user@example.com');
        $user->allows('hasVerifiedEmail')->returns(false);
        $user->expects('markEmailAsVerified');

        $this->actingAs($user)
            ->get('/email/verify/123/' . sha1('user@example.com'))
            ->assertOk();
    }

    public function testAuthorizeReturnsFalseWhenIdDoesNotMatch(): void
    {
        $user = $this->mockUser(123, 'user@example.com');

        $this->actingAs($user)
            ->get('/email/verify/999/' . sha1('user@example.com'))
            ->assertForbidden();
    }

    public function testAuthorizeReturnsFalseWhenHashDoesNotMatch(): void
    {
        $user = $this->mockUser(123, 'user@example.com');

        $this->actingAs($user)
            ->get('/email/verify/123/wrong-hash')
            ->assertForbidden();
    }

    public function testFulfillMarksEmailAsVerifiedAndDispatchesEvent(): void
    {
        Event::fake([Verified::class]);

        $user = $this->mockUser(123, 'user@example.com');
        $user->expects('hasVerifiedEmail')->returns(false);
        $user->expects('markEmailAsVerified');

        $this->actingAs($user)
            ->get('/email/verify/123/' . sha1('user@example.com'))
            ->assertOk();

        Event::assertDispatched(Verified::class);
    }

    public function testFulfillDispatchesEventForUserUsingOnlyTheVerificationTrait(): void
    {
        Event::fake([Verified::class]);

        $user = Double::for(User::class);
        $user->allows('getKey')->returns(123);
        $user->allows('getAuthIdentifier')->returns(123);
        $user->allows('getEmailForVerification')->returns('user@example.com');
        $user->expects('hasVerifiedEmail')->returns(false);
        $user->expects('markEmailAsVerified')->returns(true);

        $this->assertNotInstanceOf(MustVerifyEmail::class, $user);

        $this->actingAs($user)
            ->get('/email/verify/123/' . sha1('user@example.com'))
            ->assertOk();

        Event::assertDispatched(Verified::class, static fn (Verified $event): bool => $event->user === $user);
    }

    public function testPassiveObserverDoesNotCauseVerifiedEventToDispatch(): void
    {
        $observedEvents = [];
        $this->app->make(Dispatcher::class)->observe(
            Verified::class,
            static function (Verified $event) use (&$observedEvents): void {
                $observedEvents[] = $event;
            }
        );

        $user = $this->mockUser(123, 'user@example.com');
        $user->expects('hasVerifiedEmail')->returns(false);
        $user->expects('markEmailAsVerified');

        $this->actingAs($user)
            ->get('/email/verify/123/' . sha1('user@example.com'))
            ->assertOk();

        $this->assertSame([], $observedEvents);
    }

    public function testFulfillSkipsWhenAlreadyVerified(): void
    {
        Event::fake([Verified::class]);

        $user = $this->mockUser(123, 'user@example.com');
        $user->expects('hasVerifiedEmail')->returns(true);
        $user->expects('markEmailAsVerified')->never();

        $this->actingAs($user)
            ->get('/email/verify/123/' . sha1('user@example.com'))
            ->assertOk();

        Event::assertNotDispatched(Verified::class);
    }

    public function testRulesReturnsEmptyArray(): void
    {
        $this->assertSame([], (new EmailVerificationRequest)->rules());
    }

    /**
     * Create a mock user with only identity/auth methods.
     *
     * Tests that exercise fulfill() add their own hasVerifiedEmail/markEmailAsVerified expectations.
     */
    private function mockUser(int|string $id, string $email): Authenticatable&MustVerifyEmail
    {
        $user = Double::for(EmailVerificationUser::class);
        $user->allows('getKey')->returns($id);
        $user->allows('getAuthIdentifier')->returns($id);
        $user->allows('getEmailForVerification')->returns($email);

        return $user;
    }
}

class EmailVerificationUser extends User implements MustVerifyEmail
{
}
