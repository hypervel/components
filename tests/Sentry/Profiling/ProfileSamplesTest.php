<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Profiling;

use ExcimerLog;
use ExcimerLogEntry;
use Hypervel\Sentry\Profiling\ProfileSamples;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\TestWith;
use Psr\Log\LoggerInterface;
use Sentry\Context\OsContext;
use Sentry\Context\RuntimeContext;
use Sentry\Event;
use Sentry\Options;
use Sentry\Profiling\Profile;

#[RequiresPhpExtension('excimer')]
class ProfileSamplesTest extends TestCase
{
    use VerifiesDoubles;

    public function testAnIdleIntervalOnlySeparatesTwoRealSamples(): void
    {
        $samples = new ProfileSamples;
        $samples->record($this->logAt(0.01), false);
        $samples->record($this->logAt(0.02), true);
        $samples->record($this->logAt(0.03), false);
        $samples->record($this->logAt(0.04), false);

        $samples->record($this->logAt(0.05, 3), true);
        $samples->record($this->logAt(0.06), false);

        $recording = $samples->finish();
        $this->assertSame([0.02, 0.03, 0.05], array_column($recording, 'timestamp'));
        $this->assertSame([], $recording[1]['trace']);
        $this->assertSame(3, $recording[2]['event_count']);
    }

    #[TestWith([false, 31.0, 100, 31.0], 'idle predecessor')]
    #[TestWith([true, 30.2, 10, 30.11], 'real predecessor')]
    public function testLongProfilesRetainOnlyTheAdjacencyNeededByTheSdk(
        bool $ownsPredecessor,
        float $lastTimestamp,
        int $eventCount,
        float $expectedDuration,
    ): void {
        $samples = new ProfileSamples;
        $samples->record($this->logAt(29.9), true);
        $samples->record($this->logAt(30.1), $ownsPredecessor);
        $samples->record($this->logAt($lastTimestamp, $eventCount), true);
        $samples->record(Double::for(ExcimerLog::class)->strict(), true);

        $recording = $samples->finish();
        $this->assertSame([30.1, $lastTimestamp], array_column($recording, 'timestamp'));
        $this->assertSame($ownsPredecessor, $recording[0]['trace'] !== []);

        $logger = Double::for(LoggerInterface::class)->strict();
        $logger->expects('warning')->with("The profile is {$expectedDuration}s which is longer than the allowed 30s, the profile will be discarded.");
        $profile = new Profile(new Options(['logger' => $logger]));
        $profile->setExcimerLog($recording);
        $event = Event::createTransaction()
            ->setTransaction('GET /')
            ->setContext('trace', ['trace_id' => '0123456789abcdef0123456789abcdef'])
            ->setOsContext(new OsContext('Linux', '6', machineType: 'x86_64'))
            ->setRuntimeContext(new RuntimeContext('PHP', PHP_VERSION, PHP_SAPI));

        $this->assertNull($profile->getFormattedData($event));
    }

    /**
     * Supply a native log entry at a known offset from the start of the profile.
     */
    private function logAt(float $timestamp, int $eventCount = 1): ExcimerLog
    {
        $entry = Double::for(ExcimerLogEntry::class)->strict();
        $entry->allows('getTrace')->returns([['file' => __FILE__, 'line' => __LINE__]]);
        $entry->allows('getTimestamp')->returns($timestamp);
        $entry->allows('getEventCount')->returns($eventCount);
        $log = Double::for(ExcimerLog::class)->strict();
        $log->allows('offsetGet')->with(0)->returns($entry);

        return $log;
    }
}
