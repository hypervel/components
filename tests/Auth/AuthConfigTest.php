<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\EloquentUserProvider;
use Hypervel\Auth\Passwords\PasswordBrokerManager;
use Hypervel\Support\Env;
use Hypervel\Tests\TestCase;

class AuthConfigTest extends TestCase
{
    public function testUserCacheEnabledIsLoadedAsBooleanFromEnvironment(): void
    {
        $config = $this->loadConfigWithEnvironmentValue('AUTH_USER_CACHE_ENABLED', '0');

        $this->assertFalse($config['providers']['users']['cache']['enabled']);
    }

    public function testUserCacheTtlIsLoadedAsIntegerFromEnvironment(): void
    {
        $config = $this->loadConfigWithEnvironmentValue('AUTH_USER_CACHE_TTL', '600');

        $this->assertSame(600, $config['providers']['users']['cache']['ttl']);
    }

    public function testUserCacheDefaultsMatchTheProviderDefaults(): void
    {
        $config = $this->loadConfigWithEnvironmentValue('AUTH_USER_CACHE_PREFIX', null);

        $this->assertSame(
            EloquentUserProvider::DEFAULT_CACHE_TTL,
            $config['providers']['users']['cache']['ttl'],
        );
        $this->assertSame(
            EloquentUserProvider::DEFAULT_CACHE_PREFIX,
            $config['providers']['users']['cache']['prefix'],
        );
    }

    public function testPasswordTimeoutIsLoadedAsIntegerFromEnvironment(): void
    {
        $config = $this->loadConfigWithEnvironmentValue('AUTH_PASSWORD_TIMEOUT', '300');

        $this->assertSame(300, $config['password_timeout']);
    }

    public function testPasswordBrokerExpiryMatchesTheManagerDefault(): void
    {
        $config = $this->loadConfigWithEnvironmentValue('AUTH_USER_CACHE_PREFIX', null);

        $this->assertSame(
            PasswordBrokerManager::DEFAULT_EXPIRE_MINUTES,
            $config['passwords']['users']['expire'],
        );
    }

    public function testVerificationExpiryIsLoadedAsIntegerFromEnvironment(): void
    {
        $config = $this->loadConfigWithEnvironmentValue('AUTH_VERIFICATION_EXPIRE', '90');

        $this->assertSame(90, $config['verification']['expire']);
    }

    public function testTimeboxDurationIsLoadedAsIntegerFromEnvironment(): void
    {
        $config = $this->loadConfigWithEnvironmentValue('AUTH_TIMEBOX_DURATION', '250000');

        $this->assertSame(250000, $config['timebox_duration']);
    }

    /**
     * Load the Auth configuration with a temporary environment value.
     *
     * @return array<string, mixed>
     */
    private function loadConfigWithEnvironmentValue(string $key, ?string $value): array
    {
        $originalPutenv = getenv($key);
        $originalServerExists = array_key_exists($key, $_SERVER);
        $originalServer = $_SERVER[$key] ?? null;
        $originalEnvExists = array_key_exists($key, $_ENV);
        $originalEnv = $_ENV[$key] ?? null;

        try {
            unset($_SERVER[$key], $_ENV[$key]);
            $value === null ? putenv($key) : putenv("{$key}={$value}");
            Env::flushRepository();

            return require dirname(__DIR__, 2) . '/src/foundation/config/auth.php';
        } finally {
            $originalPutenv === false
                ? putenv($key)
                : putenv("{$key}={$originalPutenv}");

            if ($originalServerExists) {
                $_SERVER[$key] = $originalServer;
            } else {
                unset($_SERVER[$key]);
            }

            if ($originalEnvExists) {
                $_ENV[$key] = $originalEnv;
            } else {
                unset($_ENV[$key]);
            }

            Env::flushRepository();
        }
    }
}
