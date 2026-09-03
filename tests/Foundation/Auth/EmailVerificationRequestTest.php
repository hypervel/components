<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Auth;

use Hypervel\Auth\Events\Verified;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\MustVerifyEmail;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Foundation\Auth\EmailVerificationRequest;
use Hypervel\Routing\Router;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use Override;

class EmailVerificationRequestTest extends TestCase
{
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
        $user->shouldReceive('hasVerifiedEmail')->andReturn(false);
        $user->shouldReceive('markEmailAsVerified')->once();

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
        $user->shouldReceive('hasVerifiedEmail')->once()->andReturn(false);
        $user->shouldReceive('markEmailAsVerified')->once();

        $this->actingAs($user)
            ->get('/email/verify/123/' . sha1('user@example.com'))
            ->assertOk();

        Event::assertDispatched(Verified::class);
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
        $user->shouldReceive('hasVerifiedEmail')->once()->andReturn(false);
        $user->shouldReceive('markEmailAsVerified')->once();

        $this->actingAs($user)
            ->get('/email/verify/123/' . sha1('user@example.com'))
            ->assertOk();

        $this->assertSame([], $observedEvents);
    }

    public function testFulfillSkipsWhenAlreadyVerified(): void
    {
        Event::fake([Verified::class]);

        $user = $this->mockUser(123, 'user@example.com');
        $user->shouldReceive('hasVerifiedEmail')->once()->andReturn(true);
        $user->shouldNotReceive('markEmailAsVerified');

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
        $user = m::mock(Authenticatable::class, MustVerifyEmail::class);
        $user->shouldReceive('getKey')->andReturn($id);
        $user->shouldReceive('getAuthIdentifier')->andReturn($id);
        $user->shouldReceive('getEmailForVerification')->andReturn($email);

        return $user;
    }
}
