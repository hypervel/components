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

    public function testLongProfilesRetainOnlyTheAdjacencyNeededByTheSdk(): void
    {
        $samples = new ProfileSamples;
        $samples->record($this->logAt(29.0), true);
        $samples->record($this->logAt(29.9), true);
        $samples->record($this->logAt(30.1, 10), true);

        $samples->record($this->logAt(30.2), false);
        $samples->record($this->logAt(31.0, 100), true);
        $samples->record(Double::for(ExcimerLog::class)->strict(), true);

        $recording = $samples->finish();
        $this->assertSame([30.2, 31.0], array_column($recording, 'timestamp'));
        $this->assertSame([], $recording[0]['trace']);
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
