<?php

declare(strict_types=1);

namespace Hypervel\Tests\Fortify;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\StatefulGuard;
use Hypervel\Contracts\Cache\Repository as CacheRepository;
use Hypervel\Contracts\Config\Repository as Config;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Fortify\Actions\AttemptToAuthenticate;
use Hypervel\Fortify\Actions\ConfirmPassword;
use Hypervel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Hypervel\Fortify\Actions\EnableTwoFactorAuthentication;
use Hypervel\Fortify\Actions\EnsureLoginIsNotThrottled;
use Hypervel\Fortify\Actions\PrepareAuthenticatedSession;
use Hypervel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Hypervel\Fortify\Contracts\TwoFactorAuthenticationProvider as TwoFactorAuthenticationProviderContract;
use Hypervel\Fortify\Events\PasswordUpdatedViaController;
use Hypervel\Fortify\Events\RecoveryCodeReplaced;
use Hypervel\Fortify\Events\RecoveryCodesGenerated;
use Hypervel\Fortify\Events\TwoFactorAuthenticationEvent;
use Hypervel\Fortify\Features;
use Hypervel\Fortify\Fortify;
use Hypervel\Fortify\Http\Controllers\ConfirmedPasswordStatusController;
use Hypervel\Fortify\Http\Controllers\ConfirmedTwoFactorAuthenticationController;
use Hypervel\Fortify\Http\Controllers\EmailVerificationNotificationController;
use Hypervel\Fortify\Http\Controllers\EmailVerificationPromptController;
use Hypervel\Fortify\Http\Controllers\PasswordController;
use Hypervel\Fortify\Http\Controllers\PasswordResetLinkController;
use Hypervel\Fortify\Http\Controllers\ProfileInformationController;
use Hypervel\Fortify\Http\Controllers\RecoveryCodeController;
use Hypervel\Fortify\Http\Controllers\TwoFactorAuthenticationController;
use Hypervel\Fortify\Http\Controllers\VerifyEmailController;
use Hypervel\Fortify\Http\Responses\FailedPasswordResetLinkRequestResponse;
use Hypervel\Fortify\Http\Responses\FailedPasswordResetResponse;
use Hypervel\Fortify\Http\Responses\LockoutResponse;
use Hypervel\Fortify\Http\Responses\PasswordResetResponse;
use Hypervel\Fortify\Http\Responses\RedirectAsIntended;
use Hypervel\Fortify\Http\Responses\SimpleViewResponse;
use Hypervel\Fortify\Http\Responses\SuccessfulPasswordResetLinkRequestResponse;
use Hypervel\Fortify\LoginRateLimiter;
use Hypervel\Fortify\TwoFactorAuthenticationProvider;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\RedirectResponse;
use Hypervel\Http\Request;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\Support\Facades\Password;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionProperty;
use Workbench\App\Models\User;

class FortifyApiTest extends TestCase
{
    #[DataProvider('controllersWithoutRequiredConstructorsProvider')]
    public function testControllersHaveNoRequiredConstructor(string $controller): void
    {
        $constructor = (new ReflectionClass($controller))->getConstructor();

        $this->assertSame(0, $constructor?->getNumberOfRequiredParameters() ?? 0);
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function controllersWithoutRequiredConstructorsProvider(): array
    {
        return [
            'confirmed password status' => [ConfirmedPasswordStatusController::class],
            'confirmed two factor authentication' => [ConfirmedTwoFactorAuthenticationController::class],
            'email verification notification' => [EmailVerificationNotificationController::class],
            'email verification prompt' => [EmailVerificationPromptController::class],
            'password update' => [PasswordController::class],
            'password reset link' => [PasswordResetLinkController::class],
            'profile information' => [ProfileInformationController::class],
            'recovery code' => [RecoveryCodeController::class],
            'two factor authentication' => [TwoFactorAuthenticationController::class],
            'verify email' => [VerifyEmailController::class],
        ];
    }

    public function testThrottleActionKeepsUpstreamConstructor(): void
    {
        $constructor = (new ReflectionClass(EnsureLoginIsNotThrottled::class))->getConstructor();

        $this->assertSame(1, $constructor?->getNumberOfRequiredParameters());
        $this->assertSame(1, $constructor?->getNumberOfParameters());
        $this->assertInstanceOf(
            EnsureLoginIsNotThrottled::class,
            new EnsureLoginIsNotThrottled($this->app->make(LoginRateLimiter::class)),
        );
    }

    public function testPasswordResetResponseUsesStatusOnlyConstructionForJsonAndRedirects(): void
    {
        $this->app->make(Config::class)->set('fortify.views', false);
        $response = new PasswordResetResponse(Password::PASSWORD_RESET);

        $jsonResponse = $response->toResponse(Request::create('/', server: [
            'HTTP_ACCEPT' => 'application/json',
        ]));

        $this->assertInstanceOf(JsonResponse::class, $jsonResponse);
        $this->assertSame(
            ['message' => trans(Password::PASSWORD_RESET)],
            json_decode((string) $jsonResponse->getContent(), true, flags: JSON_THROW_ON_ERROR),
        );

        $redirectResponse = $response->toResponse(Request::create('/'));

        $this->assertInstanceOf(RedirectResponse::class, $redirectResponse);
        $this->assertStringEndsWith('/home', $redirectResponse->getTargetUrl());

        $this->app->make(Config::class)->set('fortify.views', true);
        $viewRedirectResponse = $response->toResponse(Request::create('/'));

        $this->assertInstanceOf(RedirectResponse::class, $viewRedirectResponse);
        $this->assertSame(route('login'), $viewRedirectResponse->getTargetUrl());
    }

    public function testPackageConfigurationRetainsInternalDefaults(): void
    {
        $this->assertFalse(config('fortify.lowercase_usernames'));
        $this->assertTrue(Features::enabled(Features::emailVerification()));
    }

    #[DataProvider('protectedMutablePropertiesProvider')]
    public function testProtectedPropertiesRemainMutable(string $class, string $property, ?string $type): void
    {
        $reflection = new ReflectionProperty($class, $property);

        $this->assertTrue($reflection->isProtected());
        $this->assertFalse($reflection->isReadOnly());

        if ($type === null) {
            $this->assertNull($reflection->getType());
        } else {
            $this->assertSame($type, (string) $reflection->getType());
        }
    }

    /**
     * @return array<string, array{class-string, string, null|string}>
     */
    public static function protectedMutablePropertiesProvider(): array
    {
        return [
            'prepare session limiter' => [PrepareAuthenticatedSession::class, 'limiter', LoginRateLimiter::class],
            'login throttle limiter' => [EnsureLoginIsNotThrottled::class, 'limiter', LoginRateLimiter::class],
            'authentication limiter' => [AttemptToAuthenticate::class, 'limiter', LoginRateLimiter::class],
            'two factor redirect limiter' => [RedirectIfTwoFactorAuthenticatable::class, 'limiter', LoginRateLimiter::class],
            'enable two factor provider' => [EnableTwoFactorAuthentication::class, 'provider', TwoFactorAuthenticationProviderContract::class],
            'confirm two factor provider' => [ConfirmTwoFactorAuthentication::class, 'provider', TwoFactorAuthenticationProviderContract::class],
            'login rate limiter' => [LoginRateLimiter::class, 'limiter', RateLimiter::class],
            'two factor replay cache' => [TwoFactorAuthenticationProvider::class, 'cache', CacheRepository::class],
            'failed reset link status' => [FailedPasswordResetLinkRequestResponse::class, 'status', 'string'],
            'failed reset status' => [FailedPasswordResetResponse::class, 'status', 'string'],
            'reset status' => [PasswordResetResponse::class, 'status', 'string'],
            'successful reset link status' => [SuccessfulPasswordResetLinkRequestResponse::class, 'status', 'string'],
            'lockout limiter' => [LockoutResponse::class, 'limiter', LoginRateLimiter::class],
            'view' => [SimpleViewResponse::class, 'view', null],
        ];
    }

    #[DataProvider('publicMutablePropertiesProvider')]
    public function testPublicPropertiesRemainMutable(string $class, string $property, string $type): void
    {
        $reflection = new ReflectionProperty($class, $property);

        $this->assertTrue($reflection->isPublic());
        $this->assertFalse($reflection->isReadOnly());
        $this->assertSame($type, (string) $reflection->getType());
    }

    /**
     * @return array<string, array{class-string, string, string}>
     */
    public static function publicMutablePropertiesProvider(): array
    {
        return [
            'replaced recovery code user' => [RecoveryCodeReplaced::class, 'user', Authenticatable::class],
            'replacement recovery code' => [RecoveryCodeReplaced::class, 'code', 'string'],
            'generated recovery code user' => [RecoveryCodesGenerated::class, 'user', Authenticatable::class],
            'password updated user' => [PasswordUpdatedViaController::class, 'user', Authenticatable::class],
            'two factor event user' => [TwoFactorAuthenticationEvent::class, 'user', Authenticatable::class],
            'redirect name' => [RedirectAsIntended::class, 'name', 'string'],
        ];
    }

    public function testConfirmPasswordDispatchesThroughProtectedCustomCallbackMethod(): void
    {
        Fortify::confirmPasswordsUsing(static fn (): bool => false);

        $action = new FortifyApiConfirmPassword;

        $this->assertTrue($action(m::mock(StatefulGuard::class), new User, 'secret'));
        $this->assertTrue($action->customCallbackInvoked);
    }

    public function testLoginRateLimiterDispatchesThroughProtectedThrottleKeyMethod(): void
    {
        $limiter = new FortifyApiLoginRateLimiter($this->app->make(RateLimiter::class));

        $limiter->attempts(Request::create('/login'));

        $this->assertTrue($limiter->customThrottleKeyInvoked);
    }

    #[DataProvider('privateStaticFortifyPropertiesProvider')]
    public function testFortifyStaticConfigurationRemainsPrivate(string $property): void
    {
        $reflection = new ReflectionProperty(Fortify::class, $property);

        $this->assertTrue($reflection->isPrivate());
        $this->assertTrue($reflection->isStatic());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function privateStaticFortifyPropertiesProvider(): array
    {
        return [
            'authentication pipeline callback' => ['authenticateThroughCallback'],
            'authentication callback' => ['authenticateUsingCallback'],
            'password confirmation callback' => ['confirmPasswordsUsingCallback'],
            'route registration flag' => ['registersRoutes'],
            'encrypter' => ['encrypter'],
            'redirect callbacks' => ['redirectUsingCallbacks'],
        ];
    }
}

class FortifyApiConfirmPassword extends ConfirmPassword
{
    public bool $customCallbackInvoked = false;

    /**
     * Confirm the user's password using a custom callback.
     */
    protected function confirmPasswordUsingCustomCallback(Authenticatable&Model $user, ?string $password = null): bool
    {
        $this->customCallbackInvoked = true;

        return true;
    }
}

class FortifyApiLoginRateLimiter extends LoginRateLimiter
{
    public bool $customThrottleKeyInvoked = false;

    /**
     * Get the throttle key for the given request.
     */
    protected function throttleKey(Request $request): string
    {
        $this->customThrottleKeyInvoked = true;

        return 'fortify-api-test';
    }
}
