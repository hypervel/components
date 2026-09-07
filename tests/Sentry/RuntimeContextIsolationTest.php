<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Channel;
use Hypervel\Sentry\State\RuntimeContextBoundary;
use Sentry\EventType;

use function Sentry\logger;
use function Sentry\traceMetrics;

class RuntimeContextIsolationTest extends SentryTestCase
{
    public function testConcurrentExecutionContextsFlushOnlyTheirOwnLogsAndMetrics(): void
    {
        $firstReady = new Channel(1);
        $secondReady = new Channel(1);
        $releaseFirst = new Channel(1);
        $releaseSecond = new Channel(1);

        $firstCoroutineId = Coroutine::create(static function () use ($firstReady, $releaseFirst): void {
            app(RuntimeContextBoundary::class)->start();
            logger()->info('first log');
            traceMetrics()->count('first.metric', 1);
            $firstReady->push(true);
            $releaseFirst->pop();
        });
        $secondCoroutineId = Coroutine::create(static function () use ($secondReady, $releaseSecond): void {
            app(RuntimeContextBoundary::class)->start();
            logger()->info('second log');
            traceMetrics()->count('second.metric', 1);
            $secondReady->push(true);
            $releaseSecond->pop();
        });

        $this->assertTrue($firstReady->pop(1.0));
        $this->assertTrue($secondReady->pop(1.0));

        $releaseFirst->push(true);
        Coroutine::join([$firstCoroutineId]);

        $this->assertSame(['first log'], $this->capturedLogBodies());
        $this->assertSame(['first.metric'], $this->capturedMetricNames());

        $releaseSecond->push(true);
        Coroutine::join([$secondCoroutineId]);

        $this->assertSame(['first log', 'second log'], $this->capturedLogBodies());
        $this->assertSame(['first.metric', 'second.metric'], $this->capturedMetricNames());
    }

    /**
     * Return captured log bodies in flush order.
     *
     * @return list<string>
     */
    private function capturedLogBodies(): array
    {
        $events = $this->getCapturedSentryEventsOfType(EventType::logs());

        return array_map(
            static fn (array $event): string => $event[0]->getLogs()[0]->getBody(),
            $events,
        );
    }

    /**
     * Return captured metric names in flush order.
     *
     * @return list<string>
     */
    private function capturedMetricNames(): array
    {
        $events = $this->getCapturedSentryEventsOfType(EventType::metrics());

        return array_map(
            static fn (array $event): string => $event[0]->getMetrics()[0]->getName(),
            $events,
        );
    }
}
