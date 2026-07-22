<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Configuration\PrefersJsonTest;

use Exception;
use Hypervel\Auth\Middleware\Authenticate;
use Hypervel\Auth\Middleware\EnsureEmailIsVerified;
use Hypervel\Auth\Middleware\RequirePassword;
use Hypervel\Contracts\Auth\MustVerifyEmail;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Auth\User as Authenticatable;
use Hypervel\Session\Middleware\StartSession;
use Hypervel\Support\Facades\Auth;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\TestCase;
use Hypervel\Validation\ValidationException;

class PrefersJsonTest extends TestCase
{
    protected function resolveApplication(): ApplicationContract
    {
        return Application::configure(static::applicationBasePath())
            ->prefersJsonResponses()
            ->create();
    }

    public function testArrayRouteReturnsJsonUnderWildcardAccept(): void
    {
        Route::get('payload', fn () => ['message' => 'hello']);

        $this->get('payload', ['Accept' => '*/*'])
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['message' => 'hello']);
    }

    public function testThrownExceptionRendersAsJsonUnderWildcardAccept(): void
    {
        Route::get('boom', fn () => throw new Exception('boom'));

        $this->get('boom', ['Accept' => '*/*'])
            ->assertInternalServerError()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure(['message']);
    }

    public function testUnauthenticatedRouteReturnsJsonUnderWildcardAccept(): void
    {
        Route::get('protected', fn () => 'secret')->middleware(Authenticate::class);

        $this->get('protected', ['Accept' => '*/*'])
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJson(['message' => 'Unauthenticated.']);
    }

    public function testRequirePasswordMiddlewareReturnsJsonUnderWildcardAccept(): void
    {
        Route::get('password-confirm', fn () => 'page')->name('password.confirm');

        Route::get('protected', fn () => 'secret')
            ->middleware([StartSession::class, RequirePassword::class]);

        $this->withSession(['auth.password_confirmed_at' => time() - 10801])
            ->get('protected', ['Accept' => '*/*'])
            ->assertStatus(423)
            ->assertJson(['message' => 'Password confirmation required.']);
    }

    public function testEnsureEmailIsVerifiedMiddlewareReturnsJsonUnderWildcardAccept(): void
    {
        Route::get('verification-notice', fn () => 'page')->name('verification.notice');

        $user = new UnverifiedUser;
        Auth::setUser($user);

        Route::get('verified-only', fn () => 'secret')
            ->middleware(EnsureEmailIsVerified::class);

        $this->actingAs($user)
            ->get('verified-only', ['Accept' => '*/*'])
            ->assertForbidden()
            ->assertHeader('Content-Type', 'application/json');
    }

    public function testValidationExceptionRendersAsJsonUnderWildcardAccept(): void
    {
        Route::get('validate', function () {
            throw ValidationException::withMessages(['email' => 'The email field is required.']);
        });

        $this->get('validate', ['Accept' => '*/*'])
            ->assertStatus(422)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonValidationErrors(['email' => 'The email field is required.']);
    }

    public function testExplicitHtmlAcceptHeaderStillReceivesHtml(): void
    {
        Route::get('plain', fn () => 'hello');

        $this->get('plain', ['Accept' => 'text/html'])
            ->assertOk()
            ->assertSee('hello')
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8');
    }
}

class UnverifiedUser extends Authenticatable implements MustVerifyEmail
{
    protected array $guarded = [];

    public function hasVerifiedEmail(): bool
    {
        return false;
    }

    public function markEmailAsVerified(): bool
    {
        return false;
    }

    public function sendEmailVerificationNotification(): void
    {
    }

    public function getEmailForVerification(): string
    {
        return 'test@example.com';
    }

    public function getAuthIdentifier(): mixed
    {
        return 1;
    }

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthPassword(): ?string
    {
        return 'secret';
    }
}
