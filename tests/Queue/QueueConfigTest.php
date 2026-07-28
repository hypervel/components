<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Closure;
use Hypervel\Support\Env;
use Hypervel\Tests\TestCase;

class QueueConfigTest extends TestCase
{
    public function testConcurrencyNumberIsLoadedAsIntegerFromEnvironment(): void
    {
        $this->withEnvironment(['QUEUE_CONCURRENCY_NUMBER' => '5'], function (): void {
            $config = $this->loadConfig();

            $this->assertSame(5, $config['concurrency_number']);
        });
    }

    public function testSqsOverflowStorageHasSecureDefaults(): void
    {
        $this->withEnvironment([
            'SQS_OVERFLOW_ENABLED' => null,
            'SQS_OVERFLOW_STORE' => null,
            'SQS_OVERFLOW_FLUSH_ON_CLEAR' => null,
        ], function (): void {
            $config = $this->loadConfig();

            $this->assertSame([
                'enabled' => false,
                'store' => null,
                'always' => false,
                'delete_after_processing' => true,
                'flush_on_clear' => false,
            ], $config['connections']['sqs']['overflow']);
        });
    }

    public function testFileFailedJobStorageDefaultsAreOwnedByTheProvider(): void
    {
        $config = $this->loadConfig();

        $this->assertArrayNotHasKey('path', $config['failed']);
        $this->assertArrayNotHasKey('limit', $config['failed']);
    }

    protected function loadConfig(): array
    {
        return require dirname(__DIR__, 2) . '/src/foundation/config/queue.php';
    }

    protected function withEnvironment(array $variables, Closure $callback): mixed
    {
        $original = [];

        foreach ($variables as $key => $value) {
            $original[$key] = [
                'putenv' => getenv($key),
                'serverExists' => array_key_exists($key, $_SERVER),
                'server' => $_SERVER[$key] ?? null,
                'envExists' => array_key_exists($key, $_ENV),
                'env' => $_ENV[$key] ?? null,
            ];

            unset($_SERVER[$key], $_ENV[$key]);

            $value === null ? putenv($key) : putenv("{$key}={$value}");
        }

        Env::flushRepository();

        try {
            return $callback();
        } finally {
            foreach ($original as $key => $value) {
                $value['putenv'] === false
                    ? putenv($key)
                    : putenv("{$key}={$value['putenv']}");

                if ($value['serverExists']) {
                    $_SERVER[$key] = $value['server'];
                } else {
                    unset($_SERVER[$key]);
                }

                if ($value['envExists']) {
                    $_ENV[$key] = $value['env'];
                } else {
                    unset($_ENV[$key]);
                }
            }

            Env::flushRepository();
        }
    }
}
