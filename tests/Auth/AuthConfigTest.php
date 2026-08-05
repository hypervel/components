<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Support\Env;
use Hypervel\Tests\TestCase;

class AuthConfigTest extends TestCase
{
    public function testUserCacheTtlIsLoadedAsIntegerFromEnvironment(): void
    {
        $config = $this->loadConfigWithEnvironmentValue('AUTH_USERS_CACHE_TTL', '600');

        $this->assertSame(600, $config['providers']['users']['cache']['ttl']);
    }

    public function testPasswordTimeoutIsLoadedAsIntegerFromEnvironment(): void
    {
        $config = $this->loadConfigWithEnvironmentValue('AUTH_PASSWORD_TIMEOUT', '300');

        $this->assertSame(300, $config['password_timeout']);
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
    private function loadConfigWithEnvironmentValue(string $key, string $value): array
    {
        $originalPutenv = getenv($key);
        $originalServerExists = array_key_exists($key, $_SERVER);
        $originalServer = $_SERVER[$key] ?? null;
        $originalEnvExists = array_key_exists($key, $_ENV);
        $originalEnv = $_ENV[$key] ?? null;

        try {
            unset($_SERVER[$key], $_ENV[$key]);
            putenv("{$key}={$value}");
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
