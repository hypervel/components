<?php

declare(strict_types=1);

namespace Hypervel\Testing\Profile;

class ProfileTracker
{
    /**
     * The maximum number of slow tests stored per process.
     */
    protected const int LIMIT = 10;

    /**
     * The test start times.
     *
     * @var array<string, float>
     */
    protected array $startTimes = [];

    /**
     * The slowest tests collected in this process.
     *
     * @var array<int, array{name: string, duration: float}>
     */
    protected array $slowTests = [];

    /**
     * Start tracking a test.
     */
    public function start(string $testId, float $time): void
    {
        $this->startTimes[$testId] = $time;
    }

    /**
     * Stop tracking a test.
     */
    public function stop(string $testId, string $name, float $time): void
    {
        if (! isset($this->startTimes[$testId])) {
            return;
        }

        $this->slowTests[] = [
            'name' => $name,
            'duration' => $time - $this->startTimes[$testId],
        ];

        usort(
            $this->slowTests,
            static fn (array $first, array $second): int => $second['duration'] <=> $first['duration'],
        );

        $this->slowTests = array_slice($this->slowTests, 0, self::LIMIT);

        unset($this->startTimes[$testId]);
    }

    /**
     * Get the slowest tests.
     *
     * @return array<int, array{name: string, duration: float}>
     */
    public function slowTests(): array
    {
        return $this->slowTests;
    }
}
