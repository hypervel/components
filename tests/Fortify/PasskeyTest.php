<?php

declare(strict_types=1);

namespace Hypervel\Tests\Fortify;

use Hypervel\Auth\Middleware\Authenticate;
use Hypervel\Auth\Middleware\RequirePassword;
use Hypervel\Auth\Middleware\UseGuard;
use Hypervel\Context\RequestContext;
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
use RuntimeException;

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
    #[WithConfig('app.key', null)]
    #[WithConfig('app.url', null)]
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
        $this->assertSame('example.test', Passkeys::relyingPartyId());
        $this->assertSame(['https://example.test'], Passkeys::allowedOrigins());
        $this->assertSame('fortify-passkey-secret', Passkeys::userHandleSecret());

        $this->assertNull(config('passkeys.guard'));
        $this->assertSame(['web'], config('passkeys.middleware'));
        $this->assertSame(['password.confirm'], config('passkeys.management_middleware'));
        $this->assertSame('throttle:6,1', config('passkeys.throttle'));
        $this->assertSame('/', config('passkeys.redirect'));

        $request = Request::create('/');

        $this->assertFalse(Passkeys::shouldRegisterRoutes());
        $this->assertSame(Fortify::redirects('login', request: $request), Passkeys::redirectTo($request));
    }

    #[WithConfig('app.key', null)]
    #[WithConfig('app.url', null)]
    #[WithConfig('fortify.passkeys.allowed_origins', [])]
    #[WithConfig('fortify.passkeys.relying_party_id', null)]
    #[WithConfig('fortify.passkeys.timeout', 60000)]
    #[WithConfig('fortify.passkeys.user_handle_secret', null)]
    public function testNullPasskeyConfigurationCrossesTheFortifyBridgeAndFailsAtUse(): void
    {
        $this->assertNull(config('passkeys.relying_party_id'));
        $this->assertSame([], config('passkeys.allowed_origins'));
        $this->assertNull(config('passkeys.user_handle_secret'));
        $this->assertThrows(
            fn () => Passkeys::relyingPartyId(),
            RuntimeException::class,
            'Passkey relying party ID must not be empty.',
        );
        $this->assertThrows(
            fn () => Passkeys::allowedOrigins(),
            RuntimeException::class,
            'At least one passkey allowed origin must be configured.',
        );
        $this->assertThrows(
            fn () => Passkeys::userHandleSecret(),
            RuntimeException::class,
            'Passkey user handle secret must not be empty.',
        );
    }

    #[DefineEnvironment('withPasskeys')]
    #[WithConfig('fortify.passkeys.allowed_origins', ['https://configured.example.test'])]
    #[WithConfig('fortify.passkeys.relying_party_id', 'configured.example.test')]
    #[WithConfig('fortify.passkeys.timeout', 60000)]
    #[WithConfig('fortify.passkeys.user_handle_secret', 'fortify-passkey-secret')]
    public function testRequestAwarePasskeyConfigurationOverridesFortifyBridgeConfig(): void
    {
        RequestContext::set(Request::create('https://dynamic.example.test/passkeys/login/options'));

        Passkeys::resolveRelyingPartyIdUsing(
            static fn (Request $request): string => $request->getHost(),
        );
        Passkeys::resolveAllowedOriginsUsing(
            static fn (Request $request): array => ['https://' . $request->getHost()],
        );

        $this->assertSame('configured.example.test', config('passkeys.relying_party_id'));
        $this->assertSame(['https://configured.example.test'], config('passkeys.allowed_origins'));
        $this->assertSame('dynamic.example.test', Passkeys::relyingPartyId());
        $this->assertSame(['https://dynamic.example.test'], Passkeys::allowedOrigins());
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

    public function testPackageConfigUsesExplicitPasskeyValuesWhenApplicationUrlAndKeyAreNull(): void
    {
        config([
            'app.key' => null,
            'app.url' => null,
        ]);
        $this->setEnvironmentValue('PASSKEYS_RELYING_PARTY_ID', 'example.com');
        $this->setEnvironmentValue('PASSKEYS_ALLOWED_ORIGINS', 'https://example.com, https://www.example.com');
        $this->setEnvironmentValue('PASSKEYS_USER_HANDLE_SECRET', 'explicit-secret');

        try {
            $config = require dirname(__DIR__, 2) . '/src/fortify/config/fortify.php';

            $this->assertSame('example.com', $config['passkeys']['relying_party_id']);
            $this->assertSame(['https://example.com', 'https://www.example.com'], $config['passkeys']['allowed_origins']);
            $this->assertSame('explicit-secret', $config['passkeys']['user_handle_secret']);
            $this->assertSame(60000, $config['passkeys']['timeout']);
            $this->assertSame('6,1', $config['limiters']['verification']);
        } finally {
            $this->unsetEnvironmentValue('PASSKEYS_RELYING_PARTY_ID');
            $this->unsetEnvironmentValue('PASSKEYS_ALLOWED_ORIGINS');
            $this->unsetEnvironmentValue('PASSKEYS_USER_HANDLE_SECRET');
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
