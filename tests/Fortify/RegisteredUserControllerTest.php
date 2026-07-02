<?php

declare(strict_types=1);

namespace Hypervel\Tests\Fortify;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\Factory as AuthFactory;
use Hypervel\Contracts\Auth\StatefulGuard;
use Hypervel\Fortify\Contracts\CreatesNewUsers;
use Hypervel\Fortify\Contracts\RegisterViewResponse;
use Mockery as m;

class RegisteredUserControllerTest extends TestCase
{
    public function testTheRegisterViewIsReturned(): void
    {
        $this->mock(RegisterViewResponse::class)
            ->shouldReceive('toResponse')
            ->andReturn(response('hello world'));

        $response = $this->get('/register');

        $response->assertStatus(200);
        $response->assertSeeText('hello world');
    }

    public function testUsersCanBeCreated(): void
    {
        $this->mock(CreatesNewUsers::class)
            ->shouldReceive('create')
            ->andReturn(m::mock(Authenticatable::class));

        $this->expectsGuardLogin();

        $response = $this->post('/register', []);

        $response->assertRedirect('/home');
    }

    public function testUsersCanBeCreatedAndRedirectedToIntendedUrl(): void
    {
        $this->mock(CreatesNewUsers::class)
            ->shouldReceive('create')
            ->andReturn(m::mock(Authenticatable::class));

        $this->expectsGuardLogin();

        $response = $this->withSession(['url.intended' => 'http://foo.com/bar'])
            ->post('/register', []);

        $response->assertRedirect('http://foo.com/bar');
    }

    public function testUsernamesWillBeStoredCaseInsensitive(): void
    {
        $this->app->make('config')->set('fortify.lowercase_usernames', true);

        $this->mock(CreatesNewUsers::class)
            ->shouldReceive('create')
            ->with([
                'email' => 'taylor@laravel.com',
                'password' => 'password',
            ])
            ->once()
            ->andReturn(m::mock(Authenticatable::class));

        $this->expectsGuardLogin();

        $response = $this->post('/register', [
            'email' => 'TAYLOR@LARAVEL.COM',
            'password' => 'password',
        ]);

        $response->assertRedirect('/home');
    }

    public function testUsersCanBeCreatedWithRememberOption(): void
    {
        $this->mock(CreatesNewUsers::class)
            ->shouldReceive('create')
            ->once()
            ->andReturn(m::mock(Authenticatable::class));

        $this->expectsGuardLogin(remember: true);

        $response = $this->post('/register', [
            'email' => 'taylor@laravel.com',
            'password' => 'password',
            'remember' => '1',
        ]);

        $response->assertRedirect('/home');
    }

    private function expectsGuardLogin(bool $remember = false): void
    {
        $guard = m::mock(StatefulGuard::class);
        $guard->shouldReceive('login')
            ->with(m::type(Authenticatable::class), $remember)
            ->once();

        $auth = m::mock(AuthFactory::class);
        $auth->shouldReceive('guard')
            ->with(null)
            ->andReturn($guard);

        $this->app->instance(AuthFactory::class, $auth);
    }
}
