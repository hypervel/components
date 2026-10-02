<?php

declare(strict_types=1);

namespace Hypervel\Tests\Fortify;

use Hypervel\Fortify\Contracts\CreatesNewUsers;
use Hypervel\Fortify\Fortify;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\Auth;
use Hypervel\Testbench\Attributes\WithMigration;
use Workbench\App\Models\User;
use Workbench\Database\Factories\UserFactory;

#[WithMigration]
class RegisteredUserControllerTest extends TestCase
{
    use RefreshDatabase;

    public function testTheRegisterViewIsReturned(): void
    {
        Fortify::registerView(fn (): string => 'hello world');

        $response = $this->get('/register');

        $response->assertStatus(200);
        $response->assertSeeText('hello world');
    }

    public function testUsersCanBeCreated(): void
    {
        $this->mock(CreatesNewUsers::class)
            ->shouldReceive('create')
            ->andReturn($user = User::forceCreate(UserFactory::new()->raw()));

        $response = $this->post('/register', []);

        $response->assertRedirect('/home');
        $this->assertAuthenticatedAs($user);
    }

    public function testUsersCanBeCreatedAndRedirectedToIntendedUrl(): void
    {
        $this->mock(CreatesNewUsers::class)
            ->shouldReceive('create')
            ->andReturn($user = User::forceCreate(UserFactory::new()->raw()));

        $response = $this->withSession(['url.intended' => 'http://foo.com/bar'])
            ->post('/register', []);

        $response->assertRedirect('http://foo.com/bar');
        $this->assertAuthenticatedAs($user);
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
            ->andReturn($user = User::forceCreate(UserFactory::new()->raw()));

        $response = $this->post('/register', [
            'email' => 'TAYLOR@LARAVEL.COM',
            'password' => 'password',
        ]);

        $response->assertRedirect('/home');
        $this->assertAuthenticatedAs($user);
    }

    public function testUsersCanBeCreatedWithRememberOption(): void
    {
        $this->mock(CreatesNewUsers::class)
            ->shouldReceive('create')
            ->once()
            ->andReturn($user = User::forceCreate(UserFactory::new()->raw()));

        $response = $this->post('/register', [
            'email' => 'taylor@laravel.com',
            'password' => 'password',
            'remember' => '1',
        ]);

        $response->assertRedirect('/home');
        $this->assertAuthenticatedAs($user);
        $response->assertCookie(Auth::guard()->getRecallerName());
    }
}
