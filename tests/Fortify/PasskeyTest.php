<?php

declare(strict_types=1);

namespace Hypervel\Tests\Fortify;

use Hypervel\Auth\Middleware\Authenticate;
use Hypervel\Auth\Middleware\RequirePassword;
use Hypervel\Auth\Middleware\UseGuard;
use Hypervel\Fortify\Features;
use Hypervel\Fortify\Fortify;
use Hypervel\Http\Request;
use Hypervel\Passkeys\Http\Controllers\PasskeyConfirmationController;
use Hypervel\Passkeys\Http\Controllers\PasskeyLoginController;
use Hypervel\Passkeys\Passkey;
use Hypervel\Passkeys\Passkeys;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Testbench\Attributes\WithConfig;

#[DefineEnvironment('withPasskeys')]
class PasskeyTest extends TestCase
{
    public function testPasskeysPackagePasskeyModelIsUsedByDefault(): void
    {
        $this->assertSame(Passkey::class, Passkeys::passkeyModel());
    }

    public function testPasskeysRoutesAreRegisteredByDefault(): void
    {
        $this->assertTrue(Features::enabled(Features::passkeys()));
        $this->assertTrue(Features::canManagePasskeys());
        $this->assertTrue(Features::hasSecurityFeatures());
        $this->assertTrue(Features::hasProfileFeatures());

        $this->assertTrue(Route::has('passkey.login-options'));
        $this->assertTrue(Route::has('passkey.login'));
        $this->assertTrue(Route::has('passkey.confirm-options'));
        $this->assertTrue(Route::has('passkey.confirm'));
        $this->assertTrue(Route::has('passkey.registration-options'));
        $this->assertTrue(Route::has('passkey.store'));
        $this->assertTrue(Route::has('passkey.destroy'));
    }

    public function testPasskeysRoutesUseTheExpectedPasskeysControllers(): void
    {
        $verify = Route::getRoutes()->getByName('passkey.login');
        $confirm = Route::getRoutes()->getByName('passkey.confirm');

        $this->assertNotNull($verify);
        $this->assertNotNull($confirm);
        $this->assertSame(PasskeyLoginController::class . '@store', $verify->getActionName());
        $this->assertSame(PasskeyConfirmationController::class . '@store', $confirm->getActionName());
    }

    #[DefineEnvironment('withoutPasskeys')]
    public function testPasskeysRoutesAreNotRegisteredWhenFeatureIsDisabled(): void
    {
        $this->assertFalse(Features::enabled(Features::passkeys()));

        $this->assertFalse(Route::has('passkey.login-options'));
        $this->assertFalse(Route::has('passkey.login'));
        $this->assertFalse(Route::has('passkey.confirm-options'));
        $this->assertFalse(Route::has('passkey.confirm'));
        $this->assertFalse(Route::has('passkey.registration-options'));
        $this->assertFalse(Route::has('passkey.store'));
        $this->assertFalse(Route::has('passkey.destroy'));
    }

    #[DefineEnvironment('withPasskeys')]
    #[WithConfig('app.url', 'https://example.test')]
    #[WithConfig('fortify.passkeys.allowed_origins', ['https://example.test'])]
    #[WithConfig('fortify.passkeys.relying_party_id', 'example.test')]
    #[WithConfig('fortify.passkeys.timeout', 60000)]
    #[WithConfig('fortify.passkeys.user_handle_secret', 'fortify-passkey-secret')]
    public function testPasskeysConfigurationIsSynchronizedWithFortifyConfiguration(): void
    {
        $this->assertSame(config('fortify.passkeys.relying_party_id'), config('passkeys.relying_party_id'));
        $this->assertSame(config('fortify.passkeys.allowed_origins'), config('passkeys.allowed_origins'));
        $this->assertSame(config('fortify.passkeys.user_handle_secret'), config('passkeys.user_handle_secret'));
        $this->assertSame(config('fortify.passkeys.timeout'), config('passkeys.timeout'));

        $this->assertNull(config('passkeys.guard'));
        $this->assertSame(['web'], config('passkeys.middleware'));
        $this->assertSame(['password.confirm'], config('passkeys.management_middleware'));
        $this->assertSame('throttle:6,1', config('passkeys.throttle'));
        $this->assertSame('/', config('passkeys.redirect'));

        $request = Request::create('/');

        $this->assertFalse(Passkeys::shouldRegisterRoutes());
        $this->assertSame(Fortify::redirects('login', request: $request), Passkeys::redirectTo($request));
    }

    #[DefineEnvironment('withPasskeysLimiter')]
    public function testPasskeysRoutesUseThePasskeysLimiter(): void
    {
        $route = Route::getRoutes()->getByName('passkey.login-options');

        $this->assertNotNull($route);
        $this->assertContains('throttle:passkeys', $route->middleware());
    }

    public function testPasskeysManagementRoutesRequirePasswordConfirmationByDefault(): void
    {
        $route = Route::getRoutes()->getByName('passkey.registration-options');

        $this->assertNotNull($route);
        $this->assertContains('password.confirm', $route->middleware());
    }

    #[DefineEnvironment('withPasskeysConfirmingPasswords')]
    public function testPasskeysManagementRoutesCanRequirePasswordConfirmation(): void
    {
        $route = Route::getRoutes()->getByName('passkey.registration-options');

        $this->assertNotNull($route);
        $this->assertContains('password.confirm', $route->middleware());
    }

    #[DefineEnvironment('withPasskeysConfirmingPasswords')]
    #[WithConfig('fortify.guard', 'admin')]
    public function testGuardConfigRunsBeforePasskeyManagementMiddlewareAtRuntime(): void
    {
        $this->assertRouteMiddlewareRunsBefore(
            'passkey.registration-options',
            UseGuard::class . ':admin',
            Authenticate::class,
        );

        $this->assertRouteMiddlewareRunsBefore(
            'passkey.registration-options',
            UseGuard::class . ':admin',
            RequirePassword::class,
        );
    }

    #[DefineEnvironment('withPasskeysWithoutPasswordConfirmation')]
    public function testPasskeysManagementRoutesCanDisablePasswordConfirmation(): void
    {
        $route = Route::getRoutes()->getByName('passkey.registration-options');

        $this->assertNotNull($route);
        $this->assertNotContains('password.confirm', $route->middleware());
    }

    public function testPackageConfigDoesNotOverwriteAppPasskeyOptions(): void
    {
        config(['fortify-options.passkeys' => ['confirmPassword' => false]]);

        require dirname(__DIR__, 2) . '/src/fortify/config/fortify.php';

        $this->assertSame(['confirmPassword' => false], config('fortify-options.passkeys'));
    }

    public function testPackageConfigReadsPasskeyAllowedOriginsFromEnvironment(): void
    {
        $this->setEnvironmentValue('PASSKEYS_ALLOWED_ORIGINS', 'https://example.com, https://www.example.com');

        try {
            $config = require dirname(__DIR__, 2) . '/src/fortify/config/fortify.php';

            $this->assertSame(['https://example.com', 'https://www.example.com'], $config['passkeys']['allowed_origins']);
        } finally {
            $this->unsetEnvironmentValue('PASSKEYS_ALLOWED_ORIGINS');
        }
    }

    #[DefineEnvironment('withPasskeysConfirmingPasswords')]
    public function testPasskeyConfirmationRoutesAreNotProtectedByPasswordConfirmationMiddleware(): void
    {
        $route = Route::getRoutes()->getByName('passkey.confirm');

        $this->assertNotNull($route);
        $this->assertNotContains('password.confirm', $route->middleware());
    }

    // REMOVED: Laravel Fortify's passkeyUserModel() tests do not apply because Hypervel Passkeys use polymorphic owners instead of one configured user model.
}
