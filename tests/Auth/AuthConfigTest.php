<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Support\Env;
use Hypervel\Tests\TestCase;

class AuthConfigTest extends TestCase
{
    public function testPasswordTimeoutIsLoadedAsIntegerFromEnvironment(): void
    {
        $key = 'AUTH_PASSWORD_TIMEOUT';
        $originalPutenv = getenv($key);
        $originalServerExists = array_key_exists($key, $_SERVER);
        $originalServer = $_SERVER[$key] ?? null;
        $originalEnvExists = array_key_exists($key, $_ENV);
        $originalEnv = $_ENV[$key] ?? null;

        try {
            unset($_SERVER[$key], $_ENV[$key]);
            putenv("{$key}=300");
            Env::flushRepository();

            $config = require dirname(__DIR__, 2) . '/src/foundation/config/auth.php';

            $this->assertSame(300, $config['password_timeout']);
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
