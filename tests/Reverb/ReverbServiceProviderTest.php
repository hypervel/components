<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb;

use Hypervel\Redis\RedisProxy;
use Hypervel\Reverb\Webhooks\WebhookBatchBuffer;
use ReflectionProperty;

class ReverbServiceProviderTest extends ReverbTestCase
{
    public function testWebhookBatchBufferDefaultsToReverbRedisConnection()
    {
        $buffer = $this->app->make(WebhookBatchBuffer::class);

        $this->assertSame('reverb', $this->bufferRedisConnection($buffer)->getName());
    }

    public function testWebhookBatchBufferUsesConfiguredScalingRedisConnection()
    {
        $this->app['config']->set('reverb.servers.reverb.scaling.connection', 'queue');

        $this->app->forgetInstance(WebhookBatchBuffer::class);

        $buffer = $this->app->make(WebhookBatchBuffer::class);

        $this->assertSame('queue', $this->bufferRedisConnection($buffer)->getName());
    }

    protected function bufferRedisConnection(WebhookBatchBuffer $buffer): RedisProxy
    {
        $property = new ReflectionProperty($buffer, 'redis');

        return $property->getValue($buffer);
    }
}
