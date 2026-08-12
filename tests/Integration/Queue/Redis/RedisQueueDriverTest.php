<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue\Redis\RedisQueueDriverTest;

use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Tests\Integration\Queue\QueueTestCase;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

#[RequiresPhpExtension('redis')]
class RedisQueueDriverTest extends QueueTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set('queue.default', 'redis');
    }

    public function testRedisQueueDriverProcessesAJob(): void
    {
        RedisQueueDriverJob::$handled = false;

        RedisQueueDriverJob::dispatch();
        $this->runQueueWorkerCommand(['--once' => true]);

        $this->assertTrue(RedisQueueDriverJob::$handled);
    }
}

class RedisQueueDriverJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public static bool $handled = false;

    public function handle(): void
    {
        static::$handled = true;
    }
}
