<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Log;

use Closure;
use Hypervel\Contracts\Log\ContextLogProcessor as ContextLogProcessorContract;
use Hypervel\Log\LogManager;
use Hypervel\Testbench\TestCase;
use Monolog\LogRecord;

class ContextLoggingIntegrationTest extends TestCase
{
    /**
     * Remove the log files created by the test.
     */
    protected function tearDown(): void
    {
        foreach (['stack-a.log', 'stack-b.log'] as $filename) {
            $path = $this->app->storagePath('logs/' . $filename);

            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function testClosureBoundProcessorRunsOnceOnStackedLogger(): void
    {
        $invocationCount = 0;

        $this->app->bind(
            ContextLogProcessorContract::class,
            function () use (&$invocationCount): Closure {
                return function (LogRecord $record) use (&$invocationCount): LogRecord {
                    ++$invocationCount;

                    return $record->with(extra: [
                        ...$record->extra,
                        'custom_processor' => true,
                    ]);
                };
            }
        );

        config([
            'logging.channels.stack_test_a' => [
                'driver' => 'single',
                'path' => $this->app->storagePath() . '/logs/stack-a.log',
            ],
            'logging.channels.stack_test_b' => [
                'driver' => 'single',
                'path' => $this->app->storagePath() . '/logs/stack-b.log',
            ],
        ]);

        $manager = new LogManager($this->app);
        $stack = $manager->stack(['stack_test_a', 'stack_test_b']);
        $stack->info('test message');

        $this->assertSame(1, $invocationCount);
    }
}
