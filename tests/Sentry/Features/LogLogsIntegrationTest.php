<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Features;

use Exception;
use Hypervel\Config\Repository;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Sentry\Features\LogFeature;
use Hypervel\Support\Facades\Log;
use Hypervel\Tests\Sentry\SentryTestCase;
use Sentry\EventType;
use Sentry\Logs\LogLevel;

use function Sentry\logger;

class LogLogsIntegrationTest extends SentryTestCase
{
    protected array $defaultSetupConfig = [
        'sentry.features' => [
            LogFeature::class,
        ],
    ];

    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        tap($app['config'], static function (Repository $config) {
            $config->set('sentry.enable_logs', true);

            $config->set('logging.channels.sentry_logs', [
                'driver' => 'sentry_logs',
            ]);

            $config->set('logging.channels.sentry_logs_error_level', [
                'driver' => 'sentry_logs',
                'level' => 'error',
            ]);
        });
    }

    public function testLogChannelIsRegistered(): void
    {
        $this->expectNotToPerformAssertions();

        Log::channel('sentry_logs');
    }

    public function testLogChannelIsRegisteredWithoutDsn(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.dsn' => null,
            'sentry_test.override_dsn' => true,
            'sentry.features' => [
                LogFeature::class,
            ],
        ]);

        $this->expectNotToPerformAssertions();

        Log::channel('sentry_logs');
    }

    public function testLogChannelGeneratesLogs(): void
    {
        $logger = Log::channel('sentry_logs');

        $logger->info('Sentry Hypervel info log message');

        $logs = $this->getAndFlushCapturedLogs();

        $this->assertCount(1, $logs);

        $log = $logs[0];

        $this->assertEquals(LogLevel::info(), $log->getLevel());
        $this->assertEquals('Sentry Hypervel info log message', $log->getBody());
    }

    public function testLogChannelGeneratesLogsOnlyForConfiguredLevel(): void
    {
        $logger = Log::channel('sentry_logs_error_level');

        $logger->info('Sentry Hypervel info log message');
        $logger->warning('Sentry Hypervel warning log message');
        $logger->error('Sentry Hypervel error log message');

        $logs = $this->getAndFlushCapturedLogs();

        $this->assertCount(1, $logs);

        $log = $logs[0];

        $this->assertEquals(LogLevel::error(), $log->getLevel());
        $this->assertEquals('Sentry Hypervel error log message', $log->getBody());
    }

    public function testLogChannelCapturesExceptions(): void
    {
        $logger = Log::channel('sentry_logs');

        $logger->error('Sentry Hypervel error log message', ['exception' => new Exception('Test exception')]);

        $logs = $this->getAndFlushCapturedLogs();

        $this->assertCount(1, $logs);

        $log = $logs[0];

        $this->assertEquals(LogLevel::error(), $log->getLevel());
        $this->assertEquals('Sentry Hypervel error log message', $log->getBody());
        $this->assertNull($log->attributes()->get('exception'));
    }

    public function testLogChannelFlushesImmediatelyWhenThresholdIsReached(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.log_flush_threshold' => 2,
            'sentry.features' => [
                LogFeature::class,
            ],
        ]);

        $logger = Log::channel('sentry_logs');

        $logger->warning('Sentry Hypervel warning log message');
        $logger->error('Sentry Hypervel error log message');

        $this->assertCount(0, logger()->aggregator()->all());

        $logEvents = array_values(array_filter($this->getCapturedSentryEvents(), static function (array $event): bool {
            return $event[0]->getType() === EventType::logs();
        }));

        $this->assertCount(1, $logEvents);
        $this->assertCount(2, $logEvents[0][0]->getLogs());
        $this->assertEquals('Sentry Hypervel warning log message', $logEvents[0][0]->getLogs()[0]->getBody());
        $this->assertEquals('Sentry Hypervel error log message', $logEvents[0][0]->getLogs()[1]->getBody());
    }

    public function testLogChannelDoesNotFlushImmediatelyWhenThresholdIsNull(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.log_flush_threshold' => null,
            'sentry.features' => [
                LogFeature::class,
            ],
        ]);

        $logger = Log::channel('sentry_logs');

        $logger->warning('Sentry Hypervel warning log message');
        $logger->error('Sentry Hypervel error log message');

        $bufferedLogs = logger()->aggregator()->all();

        $this->assertCount(2, $bufferedLogs);

        $logEvents = array_values(array_filter($this->getCapturedSentryEvents(), static function (array $event): bool {
            return $event[0]->getType() === EventType::logs();
        }));

        $this->assertCount(0, $logEvents);

        logger()->aggregator()->flush();
    }

    public function testLogChannelAddsContextAsAttributes(): void
    {
        $logger = Log::channel('sentry_logs');

        $logger->info('Sentry Hypervel info log message', [
            'foo' => 'bar',
        ]);

        $logs = $this->getAndFlushCapturedLogs();

        $this->assertCount(1, $logs);

        $log = $logs[0];

        $this->assertEquals('bar', $log->attributes()->get('foo')->getValue());
    }

    /** @return \Sentry\Logs\Log[] */
    private function getAndFlushCapturedLogs(): array
    {
        $logs = logger()->aggregator()->all();

        logger()->aggregator()->flush();

        return $logs;
    }
}
