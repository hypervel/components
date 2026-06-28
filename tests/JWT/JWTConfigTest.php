<?php

declare(strict_types=1);

namespace Hypervel\Tests\JWT;

use Hypervel\Support\Env;
use Hypervel\Tests\TestCase;

class JWTConfigTest extends TestCase
{
    public function testBlacklistDurationsAreLoadedAsIntegersFromEnvironment(): void
    {
        $originalValues = $this->setEnvironmentVariables([
            'JWT_BLACKLIST_GRACE_PERIOD' => '30',
            'JWT_BLACKLIST_REFRESH_TTL' => '60',
        ]);

        try {
            Env::flushRepository();

            $config = require dirname(__DIR__, 2) . '/src/jwt/config/jwt.php';

            $this->assertSame(30, $config['blacklist_grace_period']);
            $this->assertSame(60, $config['blacklist_refresh_ttl']);
        } finally {
            $this->restoreEnvironmentVariables($originalValues);
            Env::flushRepository();
        }
    }

    public function testTtlIsLoadedAsIntegerFromEnvironment(): void
    {
        $originalValues = $this->setEnvironmentVariables([
            'JWT_TTL' => '45',
        ]);

        try {
            Env::flushRepository();

            $config = require dirname(__DIR__, 2) . '/src/jwt/config/jwt.php';

            $this->assertSame(45, $config['ttl']);
        } finally {
            $this->restoreEnvironmentVariables($originalValues);
            Env::flushRepository();
        }
    }

    public function testTtlCanBeLoadedAsNullFromEnvironment(): void
    {
        $originalValues = $this->setEnvironmentVariables([
            'JWT_TTL' => '(null)',
        ]);

        try {
            Env::flushRepository();

            $config = require dirname(__DIR__, 2) . '/src/jwt/config/jwt.php';

            $this->assertNull($config['ttl']);
        } finally {
            $this->restoreEnvironmentVariables($originalValues);
            Env::flushRepository();
        }
    }

    public function testRefreshTtlIsLoadedAsIntegerFromEnvironment(): void
    {
        $originalValues = $this->setEnvironmentVariables([
            'JWT_REFRESH_TTL' => '90',
        ]);

        try {
            Env::flushRepository();

            $config = require dirname(__DIR__, 2) . '/src/jwt/config/jwt.php';

            $this->assertSame(90, $config['refresh_ttl']);
        } finally {
            $this->restoreEnvironmentVariables($originalValues);
            Env::flushRepository();
        }
    }

    public function testRefreshTtlCanBeLoadedAsNullFromEnvironment(): void
    {
        $originalValues = $this->setEnvironmentVariables([
            'JWT_REFRESH_TTL' => '(null)',
        ]);

        try {
            Env::flushRepository();

            $config = require dirname(__DIR__, 2) . '/src/jwt/config/jwt.php';

            $this->assertNull($config['refresh_ttl']);
        } finally {
            $this->restoreEnvironmentVariables($originalValues);
            Env::flushRepository();
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
