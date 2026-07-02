<?php

declare(strict_types=1);

namespace Hypervel\Tests\Fortify;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Fortify\Contracts\ConfirmPasswordViewResponse;
use Hypervel\Fortify\Fortify;
use Hypervel\Foundation\Auth\User;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\Date;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Attributes\WithMigration;

#[WithMigration]
class ConfirmablePasswordControllerTest extends TestCase
{
    use RefreshDatabase;

    protected TestConfirmPasswordUser $user;

    protected function afterRefreshingDatabase(): void
    {
        $this->user = TestConfirmPasswordUser::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => bcrypt('secret'),
        ]);
    }

    public function testTheConfirmPasswordViewIsReturned(): void
    {
        $this->mock(ConfirmPasswordViewResponse::class)
            ->shouldReceive('toResponse')
            ->andReturn(response('hello world'));

        $response = $this->withoutExceptionHandling()->actingAs($this->user)->get(
            '/user/confirm-password'
        );

        $response->assertStatus(200);
        $response->assertSeeText('hello world');
    }

    public function testPasswordCanBeConfirmed(): void
    {
        $this->freezeSecond();

        $response = $this->withoutExceptionHandling()
            ->actingAs($this->user)
            ->withSession(['url.intended' => 'http://foo.com/bar'])
            ->post(
                '/user/confirm-password',
                ['password' => 'secret']
            );

        $response->assertSessionHas('auth.password_confirmed_at', Date::now()->unix());
        $response->assertRedirect('http://foo.com/bar');
    }

    public function testPasswordConfirmationCanFailWithAnInvalidPassword(): void
    {
        $response = $this->withoutExceptionHandling()
            ->actingAs($this->user)
            ->withSession(['url.intended' => 'http://foo.com/bar'])
            ->post(
                '/user/confirm-password',
                ['password' => 'invalid']
            );

        $response->assertSessionHasErrors(['password']);
        $response->assertSessionMissing('auth.password_confirmed_at');
        $response->assertRedirect();
        $this->assertNotEquals($response->getTargetUrl(), 'http://foo.com/bar');
    }

    public function testPasswordConfirmationCanFailWithoutAPassword(): void
    {
        $response = $this->withoutExceptionHandling()
            ->actingAs($this->user)
            ->withSession(['url.intended' => 'http://foo.com/bar'])
            ->post(
                '/user/confirm-password',
                ['password' => null]
            );

        $response->assertSessionHasErrors(['password']);
        $response->assertSessionMissing('auth.password_confirmed_at');
        $response->assertRedirect();
        $this->assertNotEquals($response->getTargetUrl(), 'http://foo.com/bar');
    }

    public function testPasswordConfirmationCanBeCustomized(): void
    {
        $this->freezeSecond();

        Fortify::confirmPasswordsUsing(function (): bool {
            return true;
        });

        $response = $this->withoutExceptionHandling()
            ->actingAs($this->user)
            ->withSession(['url.intended' => 'http://foo.com/bar'])
            ->post(
                '/user/confirm-password',
                ['password' => 'invalid']
            );

        $response->assertSessionHas('auth.password_confirmed_at', Date::now()->unix());
        $response->assertRedirect('http://foo.com/bar');

        Fortify::flushState();
    }

    public function testPasswordConfirmationCanBeCustomizedAndFailWithoutPassword(): void
    {
        $this->freezeSecond();

        Fortify::confirmPasswordsUsing(function (): bool {
            return true;
        });

        $response = $this->withoutExceptionHandling()
            ->actingAs($this->user)
            ->withSession(['url.intended' => 'http://foo.com/bar'])
            ->post(
                '/user/confirm-password',
                ['password' => null]
            );

        $response->assertSessionHas('auth.password_confirmed_at', Date::now()->unix());
        $response->assertRedirect('http://foo.com/bar');

        Fortify::flushState();
    }

    public function testPasswordCanBeConfirmedWithJson(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson(
                '/user/confirm-password',
                ['password' => 'secret']
            );

        $response->assertStatus(201);
    }

    public function testPasswordConfirmationCanFailWithJson(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson(
                '/user/confirm-password',
                ['password' => 'invalid']
            );

        $response->assertJsonValidationErrors('password');
    }

    #[WithConfig('auth.password_timeout', 120)]
    public function testPasswordConfirmationStatusHasBeenConfirmed(): void
    {
        $this->freezeSecond();

        $response = $this->withoutExceptionHandling()
            ->actingAs($this->user)
            ->withSession(['auth.password_confirmed_at' => now()->subMinute(1)->unix()])
            ->get(
                '/user/confirmed-password-status',
            );

        $response->assertOk()
            ->assertJson(['confirmed' => true])
            ->assertHeader('X-Retry-After', 60);
    }

    #[WithConfig('auth.password_timeout', 120)]
    public function testPasswordConfirmationStatusHasExpired(): void
    {
        $this->freezeSecond();

        $response = $this->withoutExceptionHandling()
            ->actingAs($this->user)
            ->withSession(['auth.password_confirmed_at' => now()->subMinutes(10)->unix()])
            ->get(
                '/user/confirmed-password-status',
            );

        $response->assertOk()
            ->assertJson(['confirmed' => false])
            ->assertHeaderMissing('X-Retry-After');
    }

    #[WithConfig('auth.password_timeout', 120)]
    public function testPasswordConfirmationStatusHasNotConfirmed(): void
    {
        $this->freezeSecond();

        $response = $this->withoutExceptionHandling()
            ->actingAs($this->user)
            ->get(
                '/user/confirmed-password-status',
            );

        $response->assertOk()
            ->assertJson(['confirmed' => false])
            ->assertHeaderMissing('X-Retry-After');
    }

    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set([
            'auth.providers.users.model' => TestConfirmPasswordUser::class,
        ]);
    }
}

class TestConfirmPasswordUser extends User
{
    protected ?string $table = 'users';
}
