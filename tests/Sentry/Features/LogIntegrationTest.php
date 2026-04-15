<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Features;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Sentry\Features\LogFeature;
use Hypervel\Support\Facades\Log;
use Hypervel\Tests\Sentry\SentryTestCase;
use Sentry\Severity;

class LogIntegrationTest extends SentryTestCase
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
            $config->set('logging.channels.sentry', [
                'driver' => 'sentry',
            ]);

            $config->set('logging.channels.sentry_error_level', [
                'driver' => 'sentry',
                'level' => 'error',
            ]);
        });
    }

    public function testLogChannelIsRegistered(): void
    {
        $this->expectNotToPerformAssertions();

        Log::channel('sentry');
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

        Log::channel('sentry');
    }

    public function testLogChannelGeneratesEvents(): void
    {
        $logger = Log::channel('sentry');

        $logger->info('Sentry Hypervel info log message');

        $this->assertSentryEventCount(1);

        $event = $this->getLastSentryEvent();

        $this->assertEquals(Severity::info(), $event->getLevel());
        $this->assertEquals('Sentry Hypervel info log message', $event->getMessage());
    }

    public function testLogChannelGeneratesEventsOnlyForConfiguredLevel(): void
    {
        $logger = Log::channel('sentry_error_level');

        $logger->info('Sentry Hypervel info log message');
        $logger->warning('Sentry Hypervel warning log message');
        $logger->error('Sentry Hypervel error log message');

        $this->assertSentryEventCount(1);

        $event = $this->getLastSentryEvent();

        $this->assertEquals(Severity::error(), $event->getLevel());
        $this->assertEquals('Sentry Hypervel error log message', $event->getMessage());
    }
}
