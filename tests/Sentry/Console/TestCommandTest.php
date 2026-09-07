<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Console;

use Hypervel\Sentry\Console\TestCommand;
use Hypervel\Tests\Sentry\SentryTestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Sentry\Event;
use Sentry\ExceptionDataBag;
use Sentry\Frame;
use Sentry\Stacktrace;

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

    public function testInternalFramesWithoutAnAbsoluteFilePathAreIgnored(): void
    {
        $internalFrame = new Frame(null, Frame::INTERNAL_FRAME_FILENAME, 0, inApp: false);
        $packageFrame = new Frame(
            null,
            'TestCommand.php',
            1,
            absoluteFilePath: (new ReflectionClass(TestCommand::class))->getFileName(),
            inApp: false,
        );
        $event = Event::createEvent()->setExceptions([
            new ExceptionDataBag(
                new RuntimeException('failed'),
                new Stacktrace([$internalFrame, $packageFrame]),
            ),
        ]);

        (new ReflectionMethod(TestCommand::class, 'markPackageFramesInApp'))
            ->invoke(new TestCommand, $event);

        $this->assertFalse($internalFrame->isInApp());
        $this->assertTrue($packageFrame->isInApp());
    }
}
