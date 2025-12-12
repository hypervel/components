<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Console\Doctor;

/**
 * Result container for Doctor checks.
 *
 * Collects assertions with pass/fail status and descriptions
 * for display by the CacheDoctorCommand.
 */
final class CheckResult
{
    /**
     * @var array<int, array{passed: bool, description: string}>
     */
    public array $assertions = [];

    /**
     * Record an assertion result.
     */
    public function assert(bool $condition, string $description): void
    {
        $this->assertions[] = [
            'passed' => $condition,
            'description' => $description,
        ];
    }

    /**
     * Get the number of passed assertions.
     */
    public function passCount(): int
    {
        return count(array_filter($this->assertions, fn (array $a): bool => $a['passed']));
    }

    /**
     * Get the number of failed assertions.
     */
    public function failCount(): int
    {
        return count(array_filter($this->assertions, fn (array $a): bool => ! $a['passed']));
    }

    /**
     * Check if all assertions passed.
     */
    public function passed(): bool
    {
        if (empty($this->assertions)) {
            return true;
        }

        return $this->failCount() === 0;
    }

    /**
     * Get all failed assertion descriptions.
     *
     * @return array<int, string>
     */
    public function failures(): array
    {
        return array_map(
            fn (array $a): string => $a['description'],
            array_filter($this->assertions, fn (array $a): bool => ! $a['passed'])
        );
    }
}
