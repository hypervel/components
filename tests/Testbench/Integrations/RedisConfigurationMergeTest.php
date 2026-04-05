<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Integrations;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Testbench\Attributes\ResolvesHypervel;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 * @coversNothing
 */
#[WithConfig('app.key', 'AckfSECXIvnK5r28GVIWUAxmbBSjTsmF')]
#[ResolvesHypervel('overrideHypervelConfiguration')]
class RedisConfigurationMergeTest extends TestCase
{
    #[Test]
    public function itMergesCustomRedisConnectionsWithoutDroppingFrameworkDefaults(): void
    {
        $this->assertSame('redis-fixture-host', config('database.redis.fixture.host'));
        $this->assertSame(6381, config('database.redis.fixture.port'));
        $this->assertSame(9, config('database.redis.fixture.database'));
        $this->assertNotNull(config('database.redis.reverb'));
        $this->assertNotNull(config('database.redis.cache'));
        $this->assertNotNull(config('database.redis.session'));
        $this->assertNotNull(config('database.redis.queue'));
    }

    protected function overrideHypervelConfiguration(ApplicationContract $app): void
    {
        $app->useConfigPath(__DIR__ . '/../Fixtures/config');
    }
}
