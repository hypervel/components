<?php

declare(strict_types=1);

namespace Hypervel\Testing\PHPUnit;

class SlowTestTracker
{
    /**
     * The slow test threshold in seconds.
     */
    private float $threshold;

    /**
     * The test start times.
     *
     * @var array<string, float>
     */
    private array $startTimes = [];

    /**
     * The slow tests.
     *
     * @var array<int, array{name: string, duration: float}>
     */
    private array $slowTests = [];

    /**
     * Create a new slow test tracker.
     */
    public function __construct(float $threshold = 0.5)
    {
        $this->threshold = $threshold;
    }

    /**
     * Start tracking a test.
     */
    public function startTest(string $testId, float $time): void
    {
        $this->startTimes[$testId] = $time;
    }

    /**
     * Stop tracking a test.
     */
    public function endTest(string $testId, string $testName, float $time): void
    {
        if (! isset($this->startTimes[$testId])) {
            return;
        }

        $duration = $time - $this->startTimes[$testId];

        if ($duration > $this->threshold) {
            $this->slowTests[] = [
                'name' => $testName,
                'duration' => $duration,
            ];
        }

        unset($this->startTimes[$testId]);
    }

    /**
     * Get the slow tests.
     *
     * @return array<int, array{name: string, duration: float}>
     */
    public function getSlowTests(): array
    {
        return $this->slowTests;
    }
}
