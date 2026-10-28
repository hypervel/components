<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

use Hypervel\Engine\Channel;
use Hypervel\Sentry\State\RuntimeContextBoundary;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Sentry\Profiling\Profile;
use Sentry\Tracing\TransactionContext;

use function Hypervel\Coroutine\parallel;

#[RequiresPhpExtension('excimer')]
class ProfilingCoroutineIsolationTest extends TestCase
{
    protected array $defaultSetupConfig = [
        'sentry.traces_sample_rate' => 1.0,
        'sentry.profiles_sample_rate' => 1.0,
    ];

    public function testTransactionProfileExcludesWorkFromAnotherRequest(): void
    {
        // @TODO Unskip once upstream supports request-aware profiling and Hypervel uses it.
        // https://github.com/getsentry/sentry-php/pull/2253
        $this->markTestSkipped('Sentry profiling does not yet isolate samples by request context.');

        $requestAStarted = new Channel(1);
        $requestBFinished = new Channel(1);

        parallel([
            function () use ($requestAStarted, $requestBFinished): void {
                $this->app->make(RuntimeContextBoundary::class)->start();
                $transaction = $this->getSentryHubFromContainer()->startTransaction(new TransactionContext('request-a'));

                try {
                    $this->requestAWork();
                    $requestAStarted->push(true);

                    // A remains profiled while it yields to B, just as when waiting for I/O.
                    $this->assertTrue($requestBFinished->pop(2.0));
                } finally {
                    $transaction->finish();
                }
            },
            function () use ($requestAStarted, $requestBFinished): void {
                try {
                    $this->assertTrue($requestAStarted->pop(2.0));
                    $this->app->make(RuntimeContextBoundary::class)->start();
                    $this->requestBWork();
                } finally {
                    $requestBFinished->push(true);
                }
            },
        ]);

        $this->assertSentryTransactionCount(1);
        $event = $this->getLastSentryEvent();
        $this->assertNotNull($event);
        $this->assertSame('request-a', $event->getTransaction());

        $profile = $event->getSdkMetadata('profile');
        $this->assertInstanceOf(Profile::class, $profile);
        $payload = $profile->getFormattedData($event);
        $this->assertNotNull($payload);

        $functions = array_column($payload['profile']['frames'], 'function');

        $this->assertContains(self::class . '::requestAWork', $functions);
        $this->assertNotContains(
            self::class . '::requestBWork',
            $functions,
            'Request A\'s profile must not contain work performed by request B while A was suspended.',
        );
    }

    /**
     * Keep request A running across several profiler ticks.
     */
    private function requestAWork(): void
    {
        $deadline = hrtime(true) + 100_000_000;

        while (hrtime(true) < $deadline);
    }

    /**
     * Keep request B running across several profiler ticks.
     */
    private function requestBWork(): void
    {
        $deadline = hrtime(true) + 100_000_000;

        while (hrtime(true) < $deadline);
    }
}
