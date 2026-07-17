<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum;

use Hypervel\Support\Env;
use Hypervel\Tests\TestCase;

class SanctumConfigTest extends TestCase
{
    public function testNullStatefulDomainsDoNotCrashConfigLoading(): void
    {
        $key = 'SANCTUM_STATEFUL_DOMAINS';
        $originalPutenv = getenv($key);
        $originalServerExists = array_key_exists($key, $_SERVER);
        $originalServer = $_SERVER[$key] ?? null;
        $originalEnvExists = array_key_exists($key, $_ENV);
        $originalEnv = $_ENV[$key] ?? null;

        try {
            $this->setEnvironmentValue($key, '(null)');

            $config = require dirname(__DIR__, 2) . '/src/sanctum/config/sanctum.php';

            $this->assertSame([''], $config['stateful_domains']);
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
