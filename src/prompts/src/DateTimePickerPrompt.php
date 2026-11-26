<?php

declare(strict_types=1);

namespace Hypervel\Prompts;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;

class DateTimePickerPrompt extends DatePickerPrompt
{
    use Concerns\InteractsWithTime;

    /**
     * Create a new DateTimePickerPrompt instance.
     */
    public function __construct(
        string $label,
        DateTimeInterface|string|null $default = null,
        DateTimeInterface|string|null $min = null,
        DateTimeInterface|string|null $max = null,
        bool|string $required = false,
        mixed $validate = null,
        ?string $hint = null,
        ?Closure $transform = null,
        public bool $withSeconds = false,
        public bool $use12Hours = false,
    ) {
        parent::__construct($label, $default, $min, $max, $required, $validate, $hint, $transform);
    }

    /**
     * Get the display value of each segment, keyed by segment name.
     *
     * @return array<string, string>
     */
    public function segmentValues(): array
    {
        return parent::segmentValues() + $this->timeSegmentValues();
    }

    /**
     * Join the given segment values for display.
     *
     * @param array<string, string> $segments
     */
    public function formatSegments(array $segments): string
    {
        return parent::formatSegments(array_slice($segments, 0, 3)) . ' ' . $this->formatTimeSegments(array_slice($segments, 3));
    }

    /**
     * Get the focusable segments, in order.
     *
     * @return list<string>
     */
    protected function segments(): array
    {
        return [...parent::segments(), ...$this->timeSegments()];
    }

    /**
     * Step the focused segment up or down.
     */
    protected function stepSegment(int $step): void
    {
        if (in_array($this->focused, $this->timeSegments(), true)) {
            $this->stepTime($step);
        } else {
            parent::stepSegment($step);
        }
    }

    /**
     * Build the date and time with the edited segment applied, or null when it is invalid.
     */
    protected function segmentDate(): ?DateTimeImmutable
    {
        return $this->timeCandidate(parent::segmentDate());
    }

    /**
     * Get the format used when displaying values in messages.
     */
    protected function displayFormat(): string
    {
        return 'Y-m-d ' . $this->timeFormat();
    }

    /**
     * Compare the instants, since the time is part of the value.
     */
    protected function compare(DateTimeImmutable $a, DateTimeImmutable $b): int
    {
        return $a <=> $b;
    }
}
