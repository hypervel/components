<?php

declare(strict_types=1);

namespace Hypervel\Doctor\Results;

use Hypervel\Doctor\EnvironmentMode;

/**
 * @phpstan-consistent-constructor
 */
class DiagnosticResult
{
    /**
     * Whether this result represents an outcome the diagnostic can fix.
     */
    public bool $fixable = false;

    /**
     * The environment modes in which the diagnostic's fix may be applied.
     *
     * An empty list places no environment constraint on the fix.
     *
     * @var list<EnvironmentMode>
     */
    public array $fixableEnvironments = [];

    public ?string $details = null;

    /**
     * The remediation message for the result.
     */
    public ?string $remediation = null;

    /**
     * The related links for the result.
     *
     * @var list<Link>
     */
    public array $links = [];

    /**
     * The structured context for the result.
     *
     * @var array<string, mixed>
     */
    public array $context = [];

    public ?string $confirmation = null;

    /**
     * The choices for the diagnostic's fix, keyed by option value.
     *
     * An empty list means the fix needs no choice.
     *
     * @var array<string, string>
     */
    public array $fixOptions = [];

    /**
     * The label for the select entry that declines a fix offering options.
     *
     * Null renders the generic skip entry.
     */
    public ?string $fixDeclineLabel = null;

    /**
     * Create a new diagnostic result instance.
     */
    public function __construct(
        public Status $status,
        public string $summary,
        public ?string $code = null,
    ) {
    }

    /**
     * Create a passing diagnostic result.
     */
    public static function pass(string $summary = 'Passed', ?string $code = null): static
    {
        return new static(Status::Pass, $summary, $code);
    }

    /**
     * Create a warning diagnostic result.
     */
    public static function warn(string $summary, ?string $code = null): static
    {
        return new static(Status::Warn, $summary, $code);
    }

    /**
     * Create a notice diagnostic result.
     */
    public static function notice(string $summary, ?string $code = null): static
    {
        return new static(Status::Notice, $summary, $code);
    }

    /**
     * Create a failing diagnostic result.
     */
    public static function fail(string $summary, ?string $code = null): static
    {
        return new static(Status::Fail, $summary, $code);
    }

    /**
     * Create a skipped diagnostic result.
     */
    public static function skip(string $summary, ?string $code = null): static
    {
        return new static(Status::Skip, $summary, $code);
    }

    /**
     * Create an error diagnostic result.
     */
    public static function error(string $summary, ?string $code = null): static
    {
        return new static(Status::Error, $summary, $code);
    }

    /**
     * Add evidence to the diagnostic result.
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
     * Add remediation text to the diagnostic result.
     */
    public function suggest(string $message): static
    {
        $this->remediation = $message;

        return $this;
    }

    /**
     * Set the confirmation prompt for the diagnostic fix.
     */
    public function confirmUsing(string $prompt): static
    {
        $this->confirmation = $prompt;

        return $this;
    }

    /**
     * Mark this result as eligible for the diagnostic's fix.
     */
    public function fixable(EnvironmentMode ...$environments): static
    {
        $this->fixable = true;
        $this->fixableEnvironments = array_values($environments);

        return $this;
    }

    /**
     * Offer a choice of repairs for the diagnostic's fix.
     *
     * @param array<string, string> $options labels keyed by option value
     * @param null|string $decline label for the entry that declines the fix
     */
    public function fixOptions(array $options, ?string $decline = null): static
    {
        $this->fixOptions = $options;
        $this->fixDeclineLabel = $decline;

        return $this;
    }

    /**
     * Determine whether the fix may be applied in the given environment mode.
     */
    public function fixableIn(EnvironmentMode $environment): bool
    {
        return $this->fixable
            && ($this->fixableEnvironments === []
                || in_array($environment, $this->fixableEnvironments, true));
    }

    /**
     * Add a related link to the diagnostic result.
     */
    public function link(Link $link): static
    {
        $this->links[] = $link;

        return $this;
    }

    /**
     * Add context to the diagnostic result.
     */
    public function withContext(string $key, mixed $value): static
    {
        $this->context[$key] = $value;

        return $this;
    }
}
