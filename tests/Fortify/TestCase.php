<?php

declare(strict_types=1);

namespace Hypervel\Tests\Fortify;

use Hypervel\Contracts\Config\Repository as Config;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Fortify\Features;
use Hypervel\Fortify\FortifyServiceProvider;
use Hypervel\Passkeys\PasskeysServiceProvider;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\TestCase as TestbenchTestCase;
use Workbench\App\Models\User;

abstract class TestCase extends TestbenchTestCase
{
    /**
     * Get package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            PasskeysServiceProvider::class,
            FortifyServiceProvider::class,
        ];
    }

    /**
     * Set up the package environment.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $config = $app->make(Config::class);
        $userModel = $config->get('auth.providers.users.model', User::class);

        $config->set([
            'app.key' => 'base64:' . base64_encode(str_repeat('a', 32)),
            'app.name' => 'Hypervel Test',
            'app.url' => 'https://example.test',
            'auth.defaults.guard' => 'web',
            'auth.guards.web' => ['driver' => 'session', 'provider' => 'users'],
            'auth.providers.users' => ['driver' => 'eloquent', 'model' => $userModel],
            'auth.passwords.users' => ['provider' => 'users', 'table' => 'password_reset_tokens'],
            'database.default' => 'testing',
            'fortify.home' => '/home',
            'fortify.passkeys.allowed_origins' => ['https://example.test'],
            'fortify.passkeys.relying_party_id' => 'example.test',
            'fortify.passkeys.timeout' => 60000,
            'fortify.passkeys.user_handle_secret' => 'fortify-passkey-secret',
        ]);
    }

    /**
     * Create fixture tables after refreshing the database.
     */
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Enable two-factor authentication.
     */
    protected function withTwoFactorAuthentication(ApplicationContract $app): void
    {
        $app->make(Config::class)->set('fortify.features', [
            Features::twoFactorAuthentication(),
        ]);
    }

    /**
     * Enable confirmed two-factor authentication.
     */
    protected function withConfirmedTwoFactorAuthentication(ApplicationContract $app): void
    {
        $app->make(Config::class)->set('fortify.features', [
            Features::twoFactorAuthentication(['confirm' => true]),
        ]);
    }

    /**
     * Enable two-factor authentication with password confirmation.
     */
    protected function withTwoFactorAuthenticationConfirmingPasswords(ApplicationContract $app): void
    {
        $app->make(Config::class)->set('fortify.features', [
            Features::twoFactorAuthentication(['confirmPassword' => true]),
        ]);
    }

    /**
     * Disable two-factor authentication.
     */
    protected function withoutTwoFactorAuthentication(ApplicationContract $app): void
    {
        $this->removeFeature($app, Features::twoFactorAuthentication());
    }

    /**
     * Enable passkeys.
     */
    protected function withPasskeys(ApplicationContract $app): void
    {
        $app->make(Config::class)->set('fortify.features', [
            Features::passkeys(),
        ]);
    }

    /**
     * Enable passkeys with password confirmation.
     */
    protected function withPasskeysConfirmingPasswords(ApplicationContract $app): void
    {
        $app->make(Config::class)->set('fortify.features', [
            Features::passkeys(['confirmPassword' => true]),
        ]);
    }

    /**
     * Enable passkeys without password confirmation.
     */
    protected function withPasskeysWithoutPasswordConfirmation(ApplicationContract $app): void
    {
        $app->make(Config::class)->set('fortify.features', [
            Features::passkeys(['confirmPassword' => false]),
        ]);
    }

    /**
     * Enable passkeys with a configured limiter.
     */
    protected function withPasskeysLimiter(ApplicationContract $app): void
    {
        $config = $app->make(Config::class);

        $config->set('fortify.features', [
            Features::passkeys(),
        ]);

        $config->set('fortify.limiters.passkeys', 'passkeys');
    }

    /**
     * Disable passkeys.
     */
    protected function withoutPasskeys(ApplicationContract $app): void
    {
        $this->removeFeature($app, Features::passkeys());
    }

    /**
     * Remove a Fortify feature from the current config.
     */
    private function removeFeature(ApplicationContract $app, string $feature): void
    {
        $config = $app->make(Config::class);
        $features = $config->array('fortify.features', []);

        if (($key = array_search($feature, $features, true)) !== false) {
            unset($features[$key]);
        }

        $config->set('fortify.features', array_values($features));
    }
}
