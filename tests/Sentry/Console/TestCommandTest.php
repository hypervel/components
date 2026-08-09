<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Console;

use Hypervel\Tests\Sentry\SentryTestCase;

class TestCommandTest extends SentryTestCase
{
    protected array $defaultSetupConfig = [
        'sentry.dsn' => null,
    ];

    public function testErrorReportingIsRestoredWhenTheCommandFails(): void
    {
        $initialErrorReporting = error_reporting(E_ERROR);

        try {
            $this->artisan('sentry:test')
                ->expectsOutputToContain('SENTRY_HYPERVEL_DSN')
                ->assertExitCode(1);

            $this->assertSame(E_ERROR, error_reporting());
        } finally {
            error_reporting($initialErrorReporting);
        }
    }
}
