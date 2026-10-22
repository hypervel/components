<?php

declare(strict_types=1);

namespace Hypervel\Doctor\Results;

/**
 * @phpstan-consistent-constructor
 */
class FixResult
{
    public ?string $details = null;

    /**
     * The structured context for the result.
     *
     * @var array<string, mixed>
     */
    public array $context = [];

    /**
     * Create a new fix result instance.
     */
    public function __construct(
        public Status $status,
        public string $summary,
        public ?string $code = null,
    ) {
    }

    /**
     * Create a passing fix result.
     */
    public static function pass(string $summary = 'Fixed', ?string $code = null): static
    {
        return new static(Status::Pass, $summary, $code);
    }

    /**
     * Create a warning fix result.
     */
    public static function warn(string $summary, ?string $code = null): static
    {
        return new static(Status::Warn, $summary, $code);
    }

    /**
     * Create a notice fix result.
     */
    public static function notice(string $summary, ?string $code = null): static
    {
        return new static(Status::Notice, $summary, $code);
    }

    /**
     * Create a failing fix result.
     */
    public static function fail(string $summary, ?string $code = null): static
    {
        return new static(Status::Fail, $summary, $code);
    }

    /**
     * Create a skipped fix result.
     */
    public static function skip(string $summary, ?string $code = null): static
    {
        return new static(Status::Skip, $summary, $code);
    }

    /**
     * Create an error fix result.
     */
    public static function error(string $summary, ?string $code = null): static
    {
        return new static(Status::Error, $summary, $code);
    }

    /**
     * Add evidence to the fix result.
     *
     * Prefer message tokens for short identifying values like versions, paths,
     * constraints, or counts. Use details for unbounded or multi-line evidence
     * such as exception messages, process output, and bullet lists.
     */
    public function withDetails(string $details): static
    {
        $this->details = $details;

        return $this;
    }

    /**
     * Add context to the fix result.
     */
    public function withContext(string $key, mixed $value): static
    {
        $this->context[$key] = $value;

        return $this;
    }
}
