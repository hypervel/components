<?php

declare(strict_types=1);

namespace Hypervel\Tests;

class SlowTestTracker
{
    private float $threshold;

    private array $startTimes = [];

    private array $slowTests = [];

    public function __construct(float $threshold = 0.5)
    {
        $this->threshold = $threshold;
    }

    public function startTest(string $testId, float $time): void
    {
        $this->startTimes[$testId] = $time;
    }

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

    public function getSlowTests(): array
    {
        return $this->slowTests;
    }
}
