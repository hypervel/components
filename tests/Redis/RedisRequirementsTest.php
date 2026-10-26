<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use Hypervel\Redis\RedisRequirements;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class RedisRequirementsTest extends TestCase
{
    #[DataProvider('clientVersions')]
    public function testClientRequirement(string $version, bool $supported): void
    {
        $error = RedisRequirements::hashFieldExpirationClientError($version);

        if ($supported) {
            $this->assertNull($error);
        } else {
            $this->assertStringContainsString($version, $error);
            $this->assertStringContainsString(RedisRequirements::HASH_FIELD_EXPIRATION_PHPREDIS_VERSION, $error);
        }
    }

    /**
     * Provide client versions around the feature requirement.
     */
    public static function clientVersions(): array
    {
        return [
            'missing' => ['0', false],
            'older' => ['6.2.0', false],
            'prerelease' => ['6.3.0RC1', false],
            'minimum' => ['6.3.0', true],
            'newer' => ['7.0.0', true],
        ];
    }

    #[DataProvider('serverVersions')]
    public function testServerRequirement(array $info, ?string $errorContains): void
    {
        $error = RedisRequirements::hashFieldExpirationServerError($info);

        if ($errorContains === null) {
            $this->assertNull($error);
        } else {
            $this->assertStringContainsString($errorContains, $error);
        }
    }

    /**
     * Provide server versions, including Valkey's Redis compatibility field.
     */
    public static function serverVersions(): array
    {
        return [
            'unknown' => [[], 'Could not determine'],
            'old Redis' => [['redis_version' => '7.4.0'], 'Redis >= 8.0.0'],
            'minimum Redis' => [['redis_version' => '8.0.0'], null],
            'new Redis' => [['redis_version' => '9.0.0'], null],
            'old Valkey' => [['valkey_version' => '8.1.0', 'redis_version' => '8.0.0'], 'Valkey >= 9.0.0'],
            'minimum Valkey' => [['valkey_version' => '9.0.0', 'redis_version' => '7.2.0'], null],
            'new Valkey' => [['valkey_version' => '10.0.0'], null],
        ];
    }
}
