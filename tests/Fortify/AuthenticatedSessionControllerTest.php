<?php

declare(strict_types=1);

namespace Hypervel\Tests\Fortify;

use Hypervel\Auth\Events\Logout;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Fortify\Contracts\LoginViewResponse;
use Hypervel\Fortify\LoginRateLimiter;
use Hypervel\Foundation\Http\FormRequest;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Http\Request;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\Support\Facades\Auth;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\Attributes\WithMigration;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use ReflectionClass;
use Workbench\App\Models\User;

#[WithMigration]
class AuthenticatedSessionControllerTest extends TestCase
{
    use RefreshDatabase;

    public function testTheLoginViewIsReturned(): void
    {
        $this->mock(LoginViewResponse::class)
            ->shouldReceive('toResponse')
            ->andReturn(response('hello world'));

        $response = $this->get('/login');

        $response->assertStatus(200);
        $response->assertSeeText('hello world');
    }

    #[TestWith([null])]
    #[TestWith([true])]
    #[TestWith([false])]
    public function testUserCanAuthenticate(?bool $remember): void
    {
        $this->assertUserCanBeAuthenticated($remember);
    }

    #[TestWith([null])]
    #[TestWith([true])]
    #[TestWith([false])]
    public function testUserCanAuthenticateUsingFailedOnUnknownFields(?bool $remember): void
    {
        FormRequest::failOnUnknownFields();

        $this->assertUserCanBeAuthenticated($remember);
    }

    protected function assertUserCanBeAuthenticated(?bool $remember = null): void
    {
        User::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => bcrypt('secret'),
        ]);

        $response = $this->withoutExceptionHandling()->post('/login', array_filter([
            'email' => 'taylor@laravel.com',
            'password' => 'secret',
            'remember' => $remember,
        ]));

        $response->assertRedirect('/home');
    }

    public function testValidationExceptionReturnedOnFailure(): void
    {
        User::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => bcrypt('secret'),
        ]);

        $response = $this->post('/login', [
            'email' => 'taylor@laravel.com',
            'password' => 'password',
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors(['email']);
    }

    public function testLoginAttemptsAreThrottled(): void
    {
        $this->mock(LoginRateLimiter::class, function ($mock) {
            $mock->shouldReceive('tooManyAttempts')->andReturn(true);
            $mock->shouldReceive('availableIn')->andReturn(10);
        });

        $response = $this->postJson('/login', [
            'email' => 'taylor@laravel.com',
            'password' => 'secret',
        ]);

        $response->assertStatus(429);
        $response->assertJsonValidationErrors(['email']);
    }

    #[DataProvider('usernameProvider')]
    public function testCantBypassThrottleWithSpecialCharacters(string $username, string $expectedResult): void
    {
        $loginRateLimiter = new LoginRateLimiter(
            $this->mock(RateLimiter::class)
        );

        $reflection = new ReflectionClass($loginRateLimiter);
        $method = $reflection->getMethod('throttleKey');
        $method->setAccessible(true);

        $request = $this->mock(
            Request::class,
            static function ($mock) use ($username) {
                $mock->shouldReceive('input')->andReturn($username);
                $mock->shouldReceive('ip')->andReturn('192.168.0.1');
            }
        );

        self::assertSame('web|' . $expectedResult . '|192.168.0.1', $method->invoke($loginRateLimiter, $request));
    }

    public static function usernameProvider(): array
    {
        return [
            'lowercase special characters' => ['ⓣⓔⓢⓣ@ⓛⓐⓡⓐⓥⓔⓛ.ⓒⓞⓜ', 'test@laravel.com'],
            'uppercase special characters' => ['ⓉⒺⓈⓉ@ⓁⒶⓇⒶⓋⒺⓁ.ⒸⓄⓂ', 'test@laravel.com'],
            'special character numbers' => ['test⑩⓸③@laravel.com', 'test1043@laravel.com'],
            'default email' => ['test@laravel.com', 'test@laravel.com'],
        ];
    }

    public function testLockoutIsScopedToGuard(): void
    {
        $loginRateLimiter = new LoginRateLimiter(
            $this->app->make(RateLimiter::class)
        );

        $request = Request::create('/login', 'POST', [
            'email' => 'taylor@laravel.com',
        ]);
        $request->server->set('REMOTE_ADDR', '192.168.0.1');

        Auth::shouldUse('web');

        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $loginRateLimiter->increment($request);
        }

        $this->assertTrue($loginRateLimiter->tooManyAttempts($request));

        Auth::shouldUse('admin');

        $this->assertFalse($loginRateLimiter->tooManyAttempts($request));
    }

    public function testTheUserCanLogoutOfTheApplication(): void
    {
        Auth::guard()->setUser(
            m::mock(Authenticatable::class)->shouldIgnoreMissing()
        );

        $response = $this->post('/logout');

        $response->assertRedirect('/');
        $this->assertNull(Auth::guard()->getUser());
    }

    public function testTheUserCanLogoutOfTheApplicationUsingJsonRequest(): void
    {
        Auth::guard()->setUser(
            m::mock(Authenticatable::class)->shouldIgnoreMissing()
        );

        $response = $this->postJson('/logout');

        $response->assertStatus(204);
        $this->assertNull(Auth::guard()->getUser());
    }

    public function testCaseInsensitiveUsernamesCanBeUsed(): void
    {
        app('config')->set('fortify.lowercase_usernames', true);

        User::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => bcrypt('secret'),
        ]);

        $response = $this->withoutExceptionHandling()->post('/login', [
            'email' => 'TAYLOR@LARAVEL.COM',
            'password' => 'secret',
        ]);

        $response->assertRedirect('/home');
    }

    public function testUsersCanLogout(): void
    {
        $user = User::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => bcrypt('secret'),
        ]);
        Event::fake([Logout::class]);

        $response = $this->actingAs($user)->post('/logout');

        $response->assertRedirect();
        $this->assertGuest();
        Event::assertDispatched(fn (Logout $logout) => $logout->user->is($user));
    }

    public function testMustBeAuthenticatedToLogout(): void
    {
        Event::fake([Logout::class]);

        $response = $this->post('/logout');

        $response->assertRedirect();
        $this->assertGuest();
        Event::assertNotDispatched(Logout::class);
    }
}
