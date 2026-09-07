<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope;

use Hypervel\Telescope\Watchers;
use Hypervel\Tests\TestCase;

class ConfigFileTest extends TestCase
{
    public function testEnvironmentBooleanValuesAreNormalized(): void
    {
        $config = $this->withEnvironmentValues([
            'TELESCOPE_BATCH_WATCHER' => '0',
            'TELESCOPE_HTTP_CLIENT_TRUNCATE_OVERSIZED' => '1',
            'TELESCOPE_DUMP_WATCHER_ALWAYS' => '1',
            'TELESCOPE_EXCEPTION_WATCHER' => '0',
            'TELESCOPE_JOB_WATCHER' => '0',
            'TELESCOPE_MAIL_WATCHER' => '0',
            'TELESCOPE_NOTIFICATION_WATCHER' => '0',
            'TELESCOPE_REDIS_WATCHER' => '0',
            'TELESCOPE_SCHEDULE_WATCHER' => '0',
            'TELESCOPE_VIEW_WATCHER' => '0',
        ], function (): array {
            return require dirname(__DIR__, 2) . '/src/telescope/config/telescope.php';
        });

        $this->assertFalse($config['watchers'][Watchers\BatchWatcher::class]);
        $this->assertTrue($config['watchers'][Watchers\ClientRequestWatcher::class]['truncate_oversized']);
        $this->assertTrue($config['watchers'][Watchers\DumpWatcher::class]['always']);
        $this->assertFalse($config['watchers'][Watchers\ExceptionWatcher::class]);
        $this->assertFalse($config['watchers'][Watchers\JobWatcher::class]);
        $this->assertFalse($config['watchers'][Watchers\MailWatcher::class]);
        $this->assertFalse($config['watchers'][Watchers\NotificationWatcher::class]);
        $this->assertFalse($config['watchers'][Watchers\RedisWatcher::class]);
        $this->assertFalse($config['watchers'][Watchers\ScheduleWatcher::class]);
        $this->assertFalse($config['watchers'][Watchers\ViewWatcher::class]);
    }

    public function testEnvironmentIntegerValuesAreNormalized(): void
    {
        $config = $this->withEnvironmentValues([
            'TELESCOPE_QUEUE_DELAY' => '20',
            'TELESCOPE_HTTP_CLIENT_REQUEST_SIZE_LIMIT' => '128',
            'TELESCOPE_HTTP_CLIENT_RESPONSE_SIZE_LIMIT' => '256',
            'TELESCOPE_REVERB_MESSAGE_SIZE_LIMIT' => '96',
            'TELESCOPE_RESPONSE_SIZE_LIMIT' => '192',
        ], function (): array {
            return require dirname(__DIR__, 2) . '/src/telescope/config/telescope.php';
        });

        $this->assertSame(20, $config['queue']['delay']);
        $this->assertSame(128, $config['watchers'][Watchers\ClientRequestWatcher::class]['request_size_limit']);
        $this->assertSame(256, $config['watchers'][Watchers\ClientRequestWatcher::class]['response_size_limit']);
        $this->assertSame(96, $config['watchers'][Watchers\ReverbWatcher::class]['message_size_limit']);
        $this->assertSame(192, $config['watchers'][Watchers\RequestWatcher::class]['size_limit']);
    }

    public function testNullQueueDelayIsPreserved(): void
    {
        $config = $this->withEnvironmentValues([
            'TELESCOPE_QUEUE_DELAY' => '(null)',
        ], function (): array {
            return require dirname(__DIR__, 2) . '/src/telescope/config/telescope.php';
        });

        $this->assertNull($config['queue']['delay']);
    }
}
