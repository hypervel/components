<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon;

use Hypervel\Support\Env;
use Hypervel\Tests\TestCase;

class HorizonConfigTest extends TestCase
{
    public function testMissingAndBlankPrefixUseApplicationScopedDefault(): void
    {
        $key = 'HORIZON_PREFIX';
        $originalPutenv = getenv($key);
        $originalServerExists = array_key_exists($key, $_SERVER);
        $originalServer = $_SERVER[$key] ?? null;
        $originalEnvExists = array_key_exists($key, $_ENV);
        $originalEnv = $_ENV[$key] ?? null;

        try {
            unset($_SERVER[$key], $_ENV[$key]);
            putenv($key);
            Env::flushRepository();

            $config = require dirname(__DIR__, 2) . '/src/horizon/config/horizon.php';

            $this->assertSame(app_id() . '_horizon:', $config['prefix']);

            putenv("{$key}=");
            $_SERVER[$key] = '';
            $_ENV[$key] = '';
            Env::flushRepository();

            $config = require dirname(__DIR__, 2) . '/src/horizon/config/horizon.php';

            $this->assertSame(app_id() . '_horizon:', $config['prefix']);
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
