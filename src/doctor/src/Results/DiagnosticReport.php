<?php

declare(strict_types=1);

namespace Hypervel\Doctor\Results;

use Hypervel\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * @implements Arrayable<array<string, mixed>>
 */
class DiagnosticReport implements Arrayable, JsonSerializable
{
    /**
     * The version of the report's machine-readable payload.
     */
    public const int VERSION = 1;

    /**
     * Create a new diagnostic report instance.
     *
     * @param list<DiagnosticOutcome> $diagnostics
     * @param list<DiagnosticFixOutcome> $fixes
     */
    public function __construct(
        protected array $diagnostics = [],
        protected array $fixes = [],
    ) {
    }

    /**
     * Get the diagnostic outcomes.
     *
     * @return list<DiagnosticOutcome>
     */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    /**
     * Get the diagnostic fix outcomes.
     *
     * @return list<DiagnosticFixOutcome>
     */
    public function fixes(): array
    {
        return $this->fixes;
    }

    /**
     * Add fix outcomes to the report.
     *
     * @param list<DiagnosticFixOutcome> $fixes
     */
    public function withFixes(array $fixes): self
    {
        return new self($this->diagnostics, [...$this->fixes, ...$fixes]);
    }

    /**
     * Determine whether the report has failures.
     */
    public function hasFailures(): bool
    {
        foreach ($this->diagnostics as $outcome) {
            if ($outcome->result->status->failed()) {
                return true;
            }
        }

        foreach ($this->fixes as $outcome) {
            if ($outcome->result->status->failed()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether the report has warnings.
     */
    public function hasWarnings(): bool
    {
        foreach ($this->diagnostics as $outcome) {
            if ($outcome->result->status === Status::Warn) {
                return true;
            }
        }

        foreach ($this->fixes as $outcome) {
            if ($outcome->result->status === Status::Warn) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the array representation of the report.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => self::VERSION,
            'diagnostics' => array_map(
                static fn (DiagnosticOutcome $outcome): array => $outcome->toArray(),
                $this->diagnostics,
            ),
            'fixes' => array_map(
                static fn (DiagnosticFixOutcome $outcome): array => $outcome->toArray(),
                $this->fixes,
            ),
        ];
    }

    /**
     * Get the JSON representation of the report.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'version' => self::VERSION,
            'diagnostics' => $this->diagnostics,
            'fixes' => $this->fixes,
        ];
    }
}
