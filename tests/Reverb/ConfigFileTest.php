<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb;

use Hypervel\Support\Env;
use Hypervel\Tests\TestCase;

class ConfigFileTest extends TestCase
{
    public function testEnvironmentScalarValuesAreNormalized(): void
    {
        $environment = [
            'REVERB_MAX_REQUEST_SIZE' => '20000',
            'REVERB_SCALING_ENABLED' => '1',
            'REVERB_APP_RATE_LIMITING_ENABLED' => '1',
            'REVERB_WEBHOOK_BATCHING_ENABLED' => '1',
        ];
        $originalValues = [];

        foreach ($environment as $key => $value) {
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

        try {
            Env::flushRepository();

            $config = require dirname(__DIR__, 2) . '/src/reverb/config/reverb.php';

            $this->assertSame(20_000, $config['servers']['reverb']['max_request_size']);
            $this->assertTrue($config['servers']['reverb']['scaling']['enabled']);
            $this->assertTrue($config['apps']['apps'][0]['rate_limiting']['enabled']);
            $this->assertTrue($config['apps']['apps'][0]['webhooks']['batching']['enabled']);
        } finally {
            foreach ($originalValues as $key => $values) {
                $values['putenv'] === false
                    ? putenv($key)
                    : putenv("{$key}={$values['putenv']}");

                if ($values['server_exists']) {
                    $_SERVER[$key] = $values['server'];
                } else {
                    unset($_SERVER[$key]);
                }

                if ($values['env_exists']) {
                    $_ENV[$key] = $values['env'];
                } else {
                    unset($_ENV[$key]);
                }
            }

            Env::flushRepository();
        }
    }
}
