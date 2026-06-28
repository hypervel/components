<?php

declare(strict_types=1);

namespace Hypervel\Tests\JWT;

use Hypervel\JWT\Http\Parser\AuthHeaders;
use Hypervel\JWT\Http\Parser\InputSource;
use Hypervel\JWT\Validations\ExpiredClaim;
use Hypervel\JWT\Validations\IssuedAtClaim;
use Hypervel\JWT\Validations\IssuerClaim;
use Hypervel\JWT\Validations\NotBeforeClaim;
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
            'JWT_REFRESH_IAT' => 'true',
            'JWT_LOCK_SUBJECT' => 'false',
            'JWT_TOKEN' => 'api_token',
        ]);

        try {
            Env::flushRepository();

            $config = require dirname(__DIR__, 2) . '/src/jwt/config/jwt.php';

            $this->assertSame('https://api.example.test', $config['issuer']);
            $this->assertTrue($config['refresh_iat']);
            $this->assertFalse($config['lock_subject']);
            $this->assertSame('api_token', $config['token']);
        } finally {
            $this->restoreEnvironmentVariables($originalValues);
            Env::flushRepository();
        }
    }

    public function testDefaultParserDoesNotIncludeCookieParser(): void
    {
        $config = require dirname(__DIR__, 2) . '/src/jwt/config/jwt.php';

        $this->assertSame([AuthHeaders::class, InputSource::class], $config['parser']);
    }

    public function testNotBeforeClaimClassIsUsedInConfiguration(): void
    {
        $config = require dirname(__DIR__, 2) . '/src/jwt/config/jwt.php';
        $contents = file_get_contents(dirname(__DIR__, 2) . '/src/jwt/config/jwt.php');

        $this->assertStringContainsString(NotBeforeClaim::class, $contents);
        $this->assertStringNotContainsString('NotBeforeCliam', $contents);
        $this->assertNotContains('Hypervel\JWT\Validations\NotBeforeCliam', $config['validations']);
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
