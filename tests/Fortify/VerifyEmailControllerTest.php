<?php

declare(strict_types=1);

namespace Hypervel\Tests\Fortify;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Foundation\Http\FormRequest;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\URL;
use Hypervel\Testbench\Attributes\WithMigration;
use Mockery as m;
use Workbench\App\Models\User;
use Workbench\Database\Factories\UserFactory;

#[WithMigration]
class VerifyEmailControllerTest extends TestCase
{
    use RefreshDatabase;

    public function testTheEmailCanBeVerified(): void
    {
        $this->assertEmailCanBeVerified();
    }

    public function testTheEmailCanBeVerifiedUsingFailedOnUnknownFields(): void
    {
        FormRequest::failOnUnknownFields(true);

        $this->assertEmailCanBeVerified();
    }

    protected function assertEmailCanBeVerified(): void
    {
        $user = $this->createUnverifiedUser([
            'email' => 'taylor@laravel.com',
        ]);

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            [
                'id' => $user->getKey(),
                'hash' => sha1($user->email),
            ]
        );

        $response = $this->actingAs($user)
            ->withSession(['url.intended' => 'http://foo.com/bar'])
            ->get($url);

        $response->assertRedirect('http://foo.com/bar');
    }

    public function testRedirectedIfEmailIsAlreadyVerified(): void
    {
        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            [
                'id' => 1,
                'hash' => sha1('taylor@laravel.com'),
            ]
        );

        $user = m::mock(Authenticatable::class);
        $user->shouldReceive('getKey')->andReturn(1);
        $user->shouldReceive('getAuthIdentifier')->andReturn(1);
        $user->shouldReceive('getEmailForVerification')->andReturn('taylor@laravel.com');
        $user->shouldReceive('hasVerifiedEmail')->andReturn(true);
        $user->shouldReceive('markEmailAsVerified')->never();

        $response = $this->actingAs($user)->get($url);

        $response->assertStatus(302);
    }

    public function testEmailIsNotVerifiedIfIdDoesNotMatch(): void
    {
        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            [
                'id' => 2,
                'hash' => sha1('taylor@laravel.com'),
            ]
        );

        $user = m::mock(Authenticatable::class);
        $user->shouldReceive('getKey')->andReturn(1);
        $user->shouldReceive('getAuthIdentifier')->andReturn(1);
        $user->shouldReceive('getEmailForVerification')->andReturn('taylor@laravel.com');

        $response = $this->actingAs($user)->get($url);

        $response->assertStatus(403);
    }

    public function testEmailIsNotVerifiedIfEmailDoesNotMatch(): void
    {
        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            [
                'id' => 1,
                'hash' => sha1('abigail@laravel.com'),
            ]
        );

        $user = m::mock(Authenticatable::class);
        $user->shouldReceive('getKey')->andReturn(1);
        $user->shouldReceive('getAuthIdentifier')->andReturn(1);
        $user->shouldReceive('getEmailForVerification')->andReturn('taylor@laravel.com');

        $response = $this->actingAs($user)->get($url);

        $response->assertStatus(403);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function createUnverifiedUser(array $attributes = []): User
    {
        return User::forceCreate(UserFactory::new()->unverified()->raw($attributes));
    }
}
