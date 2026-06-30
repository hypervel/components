<?php

declare(strict_types=1);

namespace Hypervel\Tests\Session;

use Hypervel\Container\Container;
use Hypervel\Foundation\Application;
use Hypervel\Support\Env;
use Hypervel\Tests\TestCase;

class SessionConfigTest extends TestCase
{
    public function testBooleanOptionsAreLoadedAsBooleansFromEnvironment(): void
    {
        $originalValues = $this->setEnvironmentVariables([
            'SESSION_ENCRYPT' => '1',
            'SESSION_EXPIRE_ON_CLOSE' => '0',
        ]);

        try {
            Env::flushRepository();
            new Application(dirname(__DIR__, 2));

            $config = require dirname(__DIR__, 2) . '/src/foundation/config/session.php';

            $this->assertTrue($config['encrypt']);
            $this->assertFalse($config['expire_on_close']);
        } finally {
            $this->restoreEnvironmentVariables($originalValues);
            Env::flushRepository();
            Container::setInstance(null);
        }
    }

    /**
     * Set the given environment variables.
     *
     * @param array<string, string> $values
     * @return array<string, array{putenv: false|string, server_exists: bool, server: mixed, env_exists: bool, env: mixed}>
     */
    private function setEnvironmentVariables(array $values): array
    {
        $originalValues = [];

        foreach ($values as $key => $value) {
            $originalValues[$key] = [
                'putenv' => getenv($key),
                'server_exists' => array_key_exists($key, $_SERVER),
                'server' => $_SERVER[$key] ?? null,
                'env_exists' => array_key_exists($key, $_ENV),
                'env' => $_ENV[$key] ?? null,
            ];

            unset($_SERVER[$key], $_ENV[$key]);
            putenv("{$key}={$value}");
        }

        return $originalValues;
    }

    /**
     * Restore the given environment variables.
     *
     * @param array<string, array{putenv: false|string, server_exists: bool, server: mixed, env_exists: bool, env: mixed}> $originalValues
     */
    private function restoreEnvironmentVariables(array $originalValues): void
    {
        foreach ($originalValues as $key => $value) {
            $value['putenv'] === false
                ? putenv($key)
                : putenv("{$key}={$value['putenv']}");

            if ($value['server_exists']) {
                $_SERVER[$key] = $value['server'];
            } else {
                unset($_SERVER[$key]);
            }

            if ($value['env_exists']) {
                $_ENV[$key] = $value['env'];
            } else {
                unset($_ENV[$key]);
            }
        }
    }
}
