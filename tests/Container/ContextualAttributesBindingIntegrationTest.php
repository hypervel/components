<?php

declare(strict_types=1);

namespace Hypervel\Tests\Container;

use Hypervel\Container\Attributes\Log;
use Hypervel\Support\Collection;
use Hypervel\Testbench\TestCase;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use Psr\Log\LoggerInterface;

class ContextualAttributesBindingIntegrationTest extends TestCase
{
    public function testLogAttributeCanSetName(): void
    {
        config(['logging.default' => 'testing']);
        config(['logging.channels' => [
            'testing' => [
                'driver' => 'monolog',
                'handler' => TestHandler::class,
            ],
        ]]);

        /** @var TestHandler $testHandler */
        $testHandler = resolve('log')->driver()->getLogger()->getHandlers()[0];

        $tester = resolve(LogAttributeTester::class);
        $tester->log('hello');
        $tester->logWithName('bye');

        $records = new Collection($testHandler->getRecords());

        $this->assertCount(2, $records);
        $this->assertSame('hello', $records->firstWhere(function (LogRecord $record): bool {
            return $record->channel === 'testing';
        })->message);
        $this->assertSame('bye', $records->firstWhere(function (LogRecord $record): bool {
            return $record->channel === 'look-ma-a-channel-name';
        })->message);
    }
}

class LogAttributeTester
{
    /**
     * Create a new test fixture.
     */
    public function __construct(
        #[Log('testing')]
        public LoggerInterface $logger,
        #[Log('testing', 'look-ma-a-channel-name')]
        public LoggerInterface $loggerWithName
    ) {
    }

    /**
     * Log a line through the default channel logger.
     */
    public function log(string $line): void
    {
        $this->logger->info($line);
    }

    /**
     * Log a line through the renamed channel logger.
     */
    public function logWithName(string $line): void
    {
        $this->loggerWithName->info($line);
    }
}
