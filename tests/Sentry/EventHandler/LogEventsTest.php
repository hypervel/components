<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\EventHandler;

use Hypervel\Log\Events\MessageLogged;
use Hypervel\Tests\Sentry\SentryTestCase;

/**
 * @internal
 * @coversNothing
 */
class LogEventsTest extends SentryTestCase
{
    public function testHypervelLogsAreRecordedWhenEnabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs.logs' => true,
        ]);

        $this->assertTrue($this->app['config']->get('sentry.breadcrumbs.logs'));

        $this->dispatchHypervelEvent(new MessageLogged(
            $level = 'debug',
            $message = 'test message',
            $context = ['1']
        ));

        $lastBreadcrumb = $this->getLastSentryBreadcrumb();

        $this->assertEquals($level, $lastBreadcrumb->getLevel());
        $this->assertEquals($message, $lastBreadcrumb->getMessage());
        $this->assertEquals($context, $lastBreadcrumb->getMetadata());
    }

    public function testHypervelLogsAreRecordedWhenDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs.logs' => false,
        ]);

        $this->assertFalse($this->app['config']->get('sentry.breadcrumbs.logs'));

        $this->dispatchHypervelEvent(new MessageLogged('debug', 'test message'));

        $this->assertEmpty($this->getCurrentSentryBreadcrumbs());
    }
}
