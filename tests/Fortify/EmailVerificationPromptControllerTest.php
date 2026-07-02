<?php

declare(strict_types=1);

namespace Hypervel\Tests\Fortify;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Fortify\Contracts\VerifyEmailViewResponse;
use Mockery as m;

class EmailVerificationPromptControllerTest extends TestCase
{
    public function testTheEmailVerificationPromptViewIsReturned(): void
    {
        $this->mock(VerifyEmailViewResponse::class)
            ->shouldReceive('toResponse')
            ->andReturn(response('hello world'));

        $user = m::mock(Authenticatable::class);
        $user->shouldReceive('hasVerifiedEmail')->andReturn(false);

        $response = $this->actingAs($user)->get('/email/verify');

        $response->assertStatus(200);
        $response->assertSeeText('hello world');
    }

    public function testUserIsRedirectHomeIfAlreadyVerified(): void
    {
        $this->mock(VerifyEmailViewResponse::class)
            ->shouldReceive('toResponse')
            ->andReturn(response('hello world'));

        $user = m::mock(Authenticatable::class);
        $user->shouldReceive('hasVerifiedEmail')->andReturn(true);

        $response = $this->actingAs($user)->get('/email/verify');

        $response->assertRedirect('/home');
    }

    public function testUserIsRedirectToIntendedUrlIfAlreadyVerified(): void
    {
        $this->mock(VerifyEmailViewResponse::class)
            ->shouldReceive('toResponse')
            ->andReturn(response('hello world'));

        $user = m::mock(Authenticatable::class);
        $user->shouldReceive('hasVerifiedEmail')->andReturn(true);

        $response = $this->actingAs($user)
            ->withSession(['url.intended' => 'http://foo.com/bar'])
            ->get('/email/verify');

        $response->assertRedirect('http://foo.com/bar');
    }
}
