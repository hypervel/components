<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Features;

use Hypervel\Console\Events\CommandStarting;
use Hypervel\Tests\Sentry\SentryTestCase;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @internal
 * @coversNothing
 */
class ConsoleIntegrationTest extends SentryTestCase
{
    public function testCommandBreadcrumbIsRecordedWhenEnabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs.command_info' => true,
        ]);

        $this->assertTrue($this->app['config']->get('sentry.breadcrumbs.command_info'));

        $this->dispatchCommandStartEvent();

        $lastBreadcrumb = $this->getLastSentryBreadcrumb();

        $this->assertEquals('Starting Artisan command: test:command', $lastBreadcrumb->getMessage());
        $this->assertEquals('--foo=bar', $lastBreadcrumb->getMetadata()['input']);
    }

    public function testCommandBreadcrumbIsNotRecordedWhenDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs.command_info' => false,
        ]);

        $this->assertFalse($this->app['config']->get('sentry.breadcrumbs.command_info'));

        $this->dispatchCommandStartEvent();

        $this->assertEmpty($this->getCurrentSentryBreadcrumbs());
    }

    private function dispatchCommandStartEvent(): void
    {
        $this->dispatchHypervelEvent(
            new CommandStarting(
                'test:command',
                new ArgvInput(['artisan', '--foo=bar']),
                new BufferedOutput
            )
        );
    }
}
