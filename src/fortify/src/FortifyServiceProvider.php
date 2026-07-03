<?php

declare(strict_types=1);

namespace Hypervel\Fortify;

use Carbon\FactoryImmutable;
use Hypervel\Contracts\Cache\Repository;
use Hypervel\Contracts\Config\Repository as Config;
use Hypervel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Hypervel\Fortify\Console\InstallCommand;
use Hypervel\Fortify\Contracts\EmailVerificationNotificationSentResponse as EmailVerificationNotificationSentResponseContract;
use Hypervel\Fortify\Contracts\FailedPasswordConfirmationResponse as FailedPasswordConfirmationResponseContract;
use Hypervel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse as FailedPasswordResetLinkRequestResponseContract;
use Hypervel\Fortify\Contracts\FailedPasswordResetResponse as FailedPasswordResetResponseContract;
use Hypervel\Fortify\Contracts\FailedTwoFactorLoginResponse as FailedTwoFactorLoginResponseContract;
use Hypervel\Fortify\Contracts\LockoutResponse as LockoutResponseContract;
use Hypervel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Hypervel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Hypervel\Fortify\Contracts\PasswordConfirmedResponse as PasswordConfirmedResponseContract;
use Hypervel\Fortify\Contracts\PasswordResetResponse as PasswordResetResponseContract;
use Hypervel\Fortify\Contracts\PasswordUpdateResponse as PasswordUpdateResponseContract;
use Hypervel\Fortify\Contracts\ProfileInformationUpdatedResponse as ProfileInformationUpdatedResponseContract;
use Hypervel\Fortify\Contracts\RecoveryCodesGeneratedResponse as RecoveryCodesGeneratedResponseContract;
use Hypervel\Fortify\Contracts\RedirectsIfTwoFactorAuthenticatable as RedirectsIfTwoFactorAuthenticatableContract;
use Hypervel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Hypervel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse as SuccessfulPasswordResetLinkRequestResponseContract;
use Hypervel\Fortify\Contracts\TwoFactorAuthenticationProvider as TwoFactorAuthenticationProviderContract;
use Hypervel\Fortify\Contracts\TwoFactorConfirmedResponse as TwoFactorConfirmedResponseContract;
use Hypervel\Fortify\Contracts\TwoFactorDisabledResponse as TwoFactorDisabledResponseContract;
use Hypervel\Fortify\Contracts\TwoFactorEnabledResponse as TwoFactorEnabledResponseContract;
use Hypervel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Hypervel\Fortify\Contracts\VerifyEmailResponse as VerifyEmailResponseContract;
use Hypervel\Fortify\Http\Responses\EmailVerificationNotificationSentResponse;
use Hypervel\Fortify\Http\Responses\FailedPasswordConfirmationResponse;
use Hypervel\Fortify\Http\Responses\FailedPasswordResetLinkRequestResponse;
use Hypervel\Fortify\Http\Responses\FailedPasswordResetResponse;
use Hypervel\Fortify\Http\Responses\FailedTwoFactorLoginResponse;
use Hypervel\Fortify\Http\Responses\LockoutResponse;
use Hypervel\Fortify\Http\Responses\LoginResponse;
use Hypervel\Fortify\Http\Responses\LogoutResponse;
use Hypervel\Fortify\Http\Responses\PasswordConfirmedResponse;
use Hypervel\Fortify\Http\Responses\PasswordResetResponse;
use Hypervel\Fortify\Http\Responses\PasswordUpdateResponse;
use Hypervel\Fortify\Http\Responses\ProfileInformationUpdatedResponse;
use Hypervel\Fortify\Http\Responses\RecoveryCodesGeneratedResponse;
use Hypervel\Fortify\Http\Responses\RegisterResponse;
use Hypervel\Fortify\Http\Responses\SuccessfulPasswordResetLinkRequestResponse;
use Hypervel\Fortify\Http\Responses\TwoFactorConfirmedResponse;
use Hypervel\Fortify\Http\Responses\TwoFactorDisabledResponse;
use Hypervel\Fortify\Http\Responses\TwoFactorEnabledResponse;
use Hypervel\Fortify\Http\Responses\TwoFactorLoginResponse;
use Hypervel\Fortify\Http\Responses\VerifyEmailResponse;
use Hypervel\Http\Request;
use Hypervel\Passkeys\Passkeys;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\ServiceProvider;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/fortify.php', 'fortify');

        $this->configurePasskeys();
        $this->registerResponseBindings();

        $this->app->singleton(TwoFactorAuthenticationProviderContract::class, function ($app): TwoFactorAuthenticationProvider {
            return new TwoFactorAuthenticationProvider(
                new FactoryImmutable,
                $app->make(Repository::class),
            );
        });

        $this->app->scoped(RedirectsIfTwoFactorAuthenticatableContract::class, RedirectIfTwoFactorAuthenticatable::class);
    }

    /**
     * Register the response bindings.
     */
    protected function registerResponseBindings(): void
    {
        $this->app->singleton(FailedPasswordConfirmationResponseContract::class, FailedPasswordConfirmationResponse::class);
        $this->app->singleton(FailedPasswordResetLinkRequestResponseContract::class, FailedPasswordResetLinkRequestResponse::class);
        $this->app->singleton(FailedPasswordResetResponseContract::class, FailedPasswordResetResponse::class);
        $this->app->singleton(FailedTwoFactorLoginResponseContract::class, FailedTwoFactorLoginResponse::class);
        $this->app->singleton(LockoutResponseContract::class, LockoutResponse::class);
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(LogoutResponseContract::class, LogoutResponse::class);
        $this->app->singleton(PasswordConfirmedResponseContract::class, PasswordConfirmedResponse::class);
        $this->app->singleton(PasswordResetResponseContract::class, PasswordResetResponse::class);
        $this->app->singleton(PasswordUpdateResponseContract::class, PasswordUpdateResponse::class);
        $this->app->singleton(ProfileInformationUpdatedResponseContract::class, ProfileInformationUpdatedResponse::class);
        $this->app->singleton(RecoveryCodesGeneratedResponseContract::class, RecoveryCodesGeneratedResponse::class);
        $this->app->singleton(RegisterResponseContract::class, RegisterResponse::class);
        $this->app->singleton(EmailVerificationNotificationSentResponseContract::class, EmailVerificationNotificationSentResponse::class);
        $this->app->singleton(SuccessfulPasswordResetLinkRequestResponseContract::class, SuccessfulPasswordResetLinkRequestResponse::class);
        $this->app->singleton(TwoFactorConfirmedResponseContract::class, TwoFactorConfirmedResponse::class);
        $this->app->singleton(TwoFactorDisabledResponseContract::class, TwoFactorDisabledResponse::class);
        $this->app->singleton(TwoFactorEnabledResponseContract::class, TwoFactorEnabledResponse::class);
        $this->app->singleton(TwoFactorLoginResponseContract::class, TwoFactorLoginResponse::class);
        $this->app->singleton(VerifyEmailResponseContract::class, VerifyEmailResponse::class);
    }

    /**
     * Configure passkeys integration.
     */
    protected function configurePasskeys(): void
    {
        Passkeys::ignoreRoutes();

        $config = $this->app->make(Config::class);

        $appUrl = $config->string('app.url');

        $config->set([
            'passkeys.relying_party_id' => $config->string('fortify.passkeys.relying_party_id', parse_url($appUrl, PHP_URL_HOST)),
            'passkeys.allowed_origins' => $config->array('fortify.passkeys.allowed_origins', [$appUrl]),
            'passkeys.user_handle_secret' => $config->string('fortify.passkeys.user_handle_secret', $config->string('app.key')),
            'passkeys.timeout' => $config->integer('fortify.passkeys.timeout', 60000),
        ]);

        Passkeys::redirectUsing(
            static fn (Request $request): string => Fortify::redirects('login', request: $request),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->configurePublishing();
            $this->registerCommands();
        }

        $this->configureRoutes();
    }

    /**
     * Configure the publishable resources offered by the package.
     */
    protected function configurePublishing(): void
    {
        $this->publishes([
            __DIR__ . '/../stubs/fortify.php' => config_path('fortify.php'),
        ], 'fortify-config');

        $this->publishes([
            __DIR__ . '/../stubs/CreateNewUser.stub' => app_path('Actions/Fortify/CreateNewUser.php'),
            __DIR__ . '/../stubs/FortifyServiceProvider.stub' => app_path('Providers/FortifyServiceProvider.php'),
            __DIR__ . '/../stubs/PasswordValidationRules.stub' => app_path('Actions/Fortify/PasswordValidationRules.php'),
            __DIR__ . '/../stubs/ResetUserPassword.stub' => app_path('Actions/Fortify/ResetUserPassword.php'),
            __DIR__ . '/../stubs/UpdateUserProfileInformation.stub' => app_path('Actions/Fortify/UpdateUserProfileInformation.php'),
            __DIR__ . '/../stubs/UpdateUserPassword.stub' => app_path('Actions/Fortify/UpdateUserPassword.php'),
        ], 'fortify-support');

        $this->publishesMigrations([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
            Passkeys::migrationPath() => database_path('migrations'),
        ], 'fortify-migrations');
    }

    /**
     * Configure the routes offered by the application.
     */
    protected function configureRoutes(): void
    {
        if (Fortify::shouldRegisterRoutes()) {
            $config = $this->app->make(Config::class);

            Route::group([
                'domain' => $config->get('fortify.domain'),
                'prefix' => $config->string('fortify.prefix', ''),
            ], function (): void {
                $this->loadRoutesFrom(__DIR__ . '/../routes/routes.php');
            });
        }
    }

    /**
     * Register the package's commands.
     */
    protected function registerCommands(): void
    {
        $this->commands([
            InstallCommand::class,
        ]);
    }
}
