<?php

declare(strict_types=1);

namespace Hypervel\Tests\Jwt;

use Hypervel\Jwt\Http\Parser\AuthHeaders;
use Hypervel\Jwt\JwtGuard;
use Hypervel\Jwt\Storage\CacheStorage;
use Hypervel\Jwt\Validations\ExpiredClaim;
use Hypervel\Jwt\Validations\IssuedAtClaim;
use Hypervel\Jwt\Validations\IssuerClaim;
use Hypervel\Jwt\Validations\NotBeforeClaim;
use Hypervel\Support\Env;
use Hypervel\Tests\TestCase;

class JwtConfigTest extends TestCase
{
    public function testDefaultConfigurationValues(): void
    {
        $originalValues = $this->setEnvironmentVariables(array_fill_keys([
            'JWT_DRIVER',
            'JWT_SECRET',
            'JWT_PUBLIC_KEY',
            'JWT_PRIVATE_KEY',
            'JWT_PASSPHRASE',
            'JWT_TTL',
            'JWT_REFRESH_TTL',
            'JWT_ISSUER',
            'JWT_ALGO',
            'JWT_LEEWAY',
            'JWT_BLACKLIST_ENABLED',
            'JWT_BLACKLIST_STORE',
            'JWT_REFRESH_IAT',
            'JWT_LOCK_SUBJECT',
            'JWT_TOKEN',
            'JWT_COOKIE_KEY_NAME',
            'JWT_BLACKLIST_GRACE_PERIOD',
        ], null));

        try {
            Env::flushRepository();

            $config = require dirname(__DIR__, 2) . '/src/jwt/config/jwt.php';

            $this->assertSame('lcobucci', $config['driver']);
            $this->assertNull($config['secret']);
            $this->assertSame(['public' => null, 'private' => null, 'passphrase' => null], $config['keys']);
            $this->assertSame(JwtGuard::DEFAULT_TTL, $config['ttl']);
            $this->assertSame(20160, $config['refresh_ttl']);
            $this->assertNull($config['issuer']);
            $this->assertSame('HS256', $config['algo']);
            $this->assertSame(['iat', 'sub'], $config['required_claims']);
            $this->assertSame([], $config['persistent_claims']);
            $this->assertSame(0, $config['leeway']);
            $this->assertTrue($config['blacklist_enabled']);
            $this->assertNull($config['blacklist_store']);
            $this->assertFalse($config['refresh_iat']);
            $this->assertTrue($config['lock_subject']);
            $this->assertSame('token', $config['token']);
            $this->assertSame('token', $config['cookie_key_name']);
            $this->assertSame([AuthHeaders::class], $config['parser']);
            $this->assertSame(0, $config['blacklist_grace_period']);
            $this->assertSame(['storage' => CacheStorage::class], $config['providers']);
        } finally {
            $this->restoreEnvironmentVariables($originalValues);
            Env::flushRepository();
        }
    }

    public function testBlacklistGracePeriodIsLoadedAsIntegerFromEnvironment(): void
    {
        $originalValues = $this->setEnvironmentVariables([
            'JWT_BLACKLIST_GRACE_PERIOD' => '30',
        ]);

        try {
            Env::flushRepository();

            $config = require dirname(__DIR__, 2) . '/src/jwt/config/jwt.php';

            $this->assertSame(30, $config['blacklist_grace_period']);
        } finally {
            $this->restoreEnvironmentVariables($originalValues);
            Env::flushRepository();
        }
    }

    public function testObsoleteBlacklistRefreshTtlConfigurationIsAbsent(): void
    {
        $path = dirname(__DIR__, 2) . '/src/jwt/config/jwt.php';
        $contents = file_get_contents($path);
        $config = require $path;

        $this->assertArrayNotHasKey('blacklist_refresh_ttl', $config);
        $this->assertStringNotContainsString('JWT_BLACKLIST_REFRESH_TTL', $contents);
    }

    public function testLeewayIsLoadedAsIntegerFromEnvironment(): void
    {
        $originalValues = $this->setEnvironmentVariables([
            'JWT_LEEWAY' => '30',
        ]);

        try {
            Env::flushRepository();

            $config = require dirname(__DIR__, 2) . '/src/jwt/config/jwt.php';

            $this->assertSame(30, $config['leeway']);
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

    public function testNewJwtOptionsAreLoadedFromEnvironment(): void
    {
        $originalValues = $this->setEnvironmentVariables([
            'JWT_ISSUER' => 'https://api.example.test',
            'JWT_BLACKLIST_ENABLED' => '0',
            'JWT_BLACKLIST_STORE' => 'redis',
            'JWT_REFRESH_IAT' => '1',
            'JWT_LOCK_SUBJECT' => '0',
            'JWT_TOKEN' => 'api_token',
            'JWT_COOKIE_KEY_NAME' => 'jwt_cookie',
        ]);

        try {
            Env::flushRepository();

            $config = require dirname(__DIR__, 2) . '/src/jwt/config/jwt.php';

            $this->assertSame('https://api.example.test', $config['issuer']);
            $this->assertFalse($config['blacklist_enabled']);
            $this->assertSame('redis', $config['blacklist_store']);
            $this->assertTrue($config['refresh_iat']);
            $this->assertFalse($config['lock_subject']);
            $this->assertSame('api_token', $config['token']);
            $this->assertSame('jwt_cookie', $config['cookie_key_name']);
        } finally {
            $this->restoreEnvironmentVariables($originalValues);
            Env::flushRepository();
        }
    }

    public function testNotBeforeClaimClassIsUsedInConfiguration(): void
    {
        $config = require dirname(__DIR__, 2) . '/src/jwt/config/jwt.php';
        $contents = file_get_contents(dirname(__DIR__, 2) . '/src/jwt/config/jwt.php');

        $this->assertStringContainsString(NotBeforeClaim::class, $contents);
        $this->assertStringNotContainsString('NotBeforeCliam', $contents);
        $this->assertNotContains('Hypervel\Jwt\Validations\NotBeforeCliam', $config['validations']);
    }

    public function testDefaultConfigurationValidatesStandardTemporalClaimsAndIssuer(): void
    {
        $config = require dirname(__DIR__, 2) . '/src/jwt/config/jwt.php';

        $this->assertContains(ExpiredClaim::class, $config['validations']);
        $this->assertContains(IssuerClaim::class, $config['validations']);
        $this->assertContains(IssuedAtClaim::class, $config['validations']);
        $this->assertContains(NotBeforeClaim::class, $config['validations']);
    }

    /**
     * Set the given environment variables, unsetting those given a null value.
     *
     * @param array<string, null|string> $values
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
            putenv($value === null ? $key : "{$key}={$value}");
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
