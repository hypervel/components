<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb;

use Hypervel\Reverb\Application;
use Hypervel\Tests\TestCase;

class ConfigFileTest extends TestCase
{
    public function testEnvironmentScalarValuesAreNormalizedAndDefaultsRemainAligned(): void
    {
        $environment = [
            'REVERB_SERVER_PORT' => '9080',
            'REVERB_MAX_REQUEST_SIZE' => '20000',
            'REVERB_SCALING_ENABLED' => '1',
            'REVERB_SWOOLE_SHARED_STATE_ROWS' => '32768',
            'REVERB_SWOOLE_SHARED_STATE_LOCK_ROWS' => '4096',
            'REVERB_PORT' => '8443',
            'REVERB_APP_PING_INTERVAL' => '45',
            'REVERB_APP_RATE_LIMIT_ENABLED' => '1',
            'REVERB_APP_RATE_LIMIT_MAX_ATTEMPTS' => '120',
            'REVERB_APP_RATE_LIMIT_DECAY_SECONDS' => '30',
            'REVERB_APP_RATE_LIMIT_TERMINATE_ON_LIMIT' => '0',
            'REVERB_APP_MAX_CONNECTIONS' => '500',
            'REVERB_APP_MAX_MESSAGE_SIZE' => '20000',
            'REVERB_WEBHOOK_SUBSCRIPTION_COUNT' => '1',
            'REVERB_WEBHOOK_DISCONNECT_SMOOTHING_MS' => '1500',
            'REVERB_WEBHOOK_TIMEOUT' => '10',
            'REVERB_WEBHOOK_RETRIES' => '5',
            'REVERB_WEBHOOK_RETRY_DELAY' => '2',
            'REVERB_WEBHOOK_BATCHING_ENABLED' => '1',
            'REVERB_WEBHOOK_BATCHING_MAX_EVENTS' => '100',
            'REVERB_WEBHOOK_BATCHING_MAX_DELAY_MS' => '500',
            'REVERB_WEBHOOK_BATCHING_MAX_PAYLOAD_BYTES' => '524288',
            'REVERB_APP_ACTIVITY_TIMEOUT' => null,
        ];
        $this->withEnvironmentValues($environment, function (): void {
            $config = require dirname(__DIR__, 2) . '/src/reverb/config/reverb.php';

            $this->assertSame(9080, $config['servers']['reverb']['port']);
            $this->assertSame(20_000, $config['servers']['reverb']['max_request_size']);
            $this->assertTrue($config['servers']['reverb']['scaling']['enabled']);
            $this->assertSame(32768, $config['servers']['reverb']['swoole_shared_state']['rows']);
            $this->assertSame(4096, $config['servers']['reverb']['swoole_shared_state']['lock_rows']);
            $this->assertSame(8443, $config['apps']['apps'][0]['options']['port']);
            $this->assertSame(45, $config['apps']['apps'][0]['ping_interval']);
            $this->assertTrue($config['apps']['apps'][0]['rate_limiting']['enabled']);
            $this->assertSame(120, $config['apps']['apps'][0]['rate_limiting']['max_attempts']);
            $this->assertSame(30, $config['apps']['apps'][0]['rate_limiting']['decay_seconds']);
            $this->assertFalse($config['apps']['apps'][0]['rate_limiting']['terminate_on_limit']);
            $this->assertSame(500, $config['apps']['apps'][0]['max_connections']);
            $this->assertSame(20_000, $config['apps']['apps'][0]['max_message_size']);
            $this->assertTrue($config['apps']['apps'][0]['webhooks']['subscription_count']);
            $this->assertSame(1500, $config['apps']['apps'][0]['webhooks']['disconnect_smoothing_ms']);
            $this->assertSame(10, $config['apps']['apps'][0]['webhooks']['timeout']);
            $this->assertSame(5, $config['apps']['apps'][0]['webhooks']['retries']);
            $this->assertSame(2, $config['apps']['apps'][0]['webhooks']['retry_delay']);
            $this->assertTrue($config['apps']['apps'][0]['webhooks']['batching']['enabled']);
            $this->assertSame(100, $config['apps']['apps'][0]['webhooks']['batching']['max_events']);
            $this->assertSame(500, $config['apps']['apps'][0]['webhooks']['batching']['max_delay_ms']);
            $this->assertSame(524288, $config['apps']['apps'][0]['webhooks']['batching']['max_payload_bytes']);
            $this->assertSame(Application::DEFAULT_ACTIVITY_TIMEOUT, $config['apps']['apps'][0]['activity_timeout']);
            $this->assertSame(
                Application::DEFAULT_ACCEPT_CLIENT_EVENTS_FROM,
                $config['apps']['apps'][0]['accept_client_events_from'],
            );
        });
    }
}
