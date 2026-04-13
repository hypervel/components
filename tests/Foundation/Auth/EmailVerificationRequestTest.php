<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Auth;

use Hypervel\Auth\Events\Verified;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\MustVerifyEmail;
use Hypervel\Foundation\Auth\EmailVerificationRequest;
use Hypervel\Routing\Router;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use Override;

/**
 * @internal
 * @coversNothing
 */
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

    public function testAuthorizeReturnsTrueWhenIdAndHashMatch()
    {
        $user = $this->mockUser(123, 'user@example.com');
        $user->shouldReceive('hasVerifiedEmail')->andReturn(false);
        $user->shouldReceive('markEmailAsVerified')->once();

        $this->actingAs($user)
            ->get('/email/verify/123/' . sha1('user@example.com'))
            ->assertOk();
    }

    public function testAuthorizeReturnsFalseWhenIdDoesNotMatch()
    {
        $user = $this->mockUser(123, 'user@example.com');

        $this->actingAs($user)
            ->get('/email/verify/999/' . sha1('user@example.com'))
            ->assertForbidden();
    }

    public function testAuthorizeReturnsFalseWhenHashDoesNotMatch()
    {
        $user = $this->mockUser(123, 'user@example.com');

        $this->actingAs($user)
            ->get('/email/verify/123/wrong-hash')
            ->assertForbidden();
    }

    public function testFulfillMarksEmailAsVerifiedAndDispatchesEvent()
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

    public function testFulfillSkipsWhenAlreadyVerified()
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

    public function testRulesReturnsEmptyArray()
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
