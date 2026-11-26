<?php

declare(strict_types=1);

namespace Hypervel\Prompts;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

class TimePickerPrompt extends TimeBasedPrompt
{
    use Concerns\InteractsWithTime;

    /**
     * What the prompt's values are called in argument errors.
     */
    protected string $noun = 'Time';

    /**
     * Create a new TimePickerPrompt instance.
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
     * Register the fallback implementation.
     *
     * Like the prompt itself, the fallback starts from a time within the range when the prompt is interactive.
     */
    public static function fallbackUsing(Closure $fallback): void
    {
        static::registerFallback($fallback, static fn (): bool => static::isInteractive() !== false);
    }

    /**
     * Register a fallback that starts from a time within the range when it asks for input.
     *
     * @internal
     *
     * @param Closure(): bool $interactive Whether the fallback asks for input
     */
    public static function registerFallback(Closure $fallback, Closure $interactive): void
    {
        parent::fallbackUsing(static function (self $prompt) use ($fallback, $interactive): mixed {
            if ($interactive()) {
                $prompt->goTo($prompt->date);
            }

            return $fallback($prompt);
        });
    }

    /**
     * Get the display value of each segment, keyed by segment name.
     *
     * @return array<string, string>
     */
    public function segmentValues(): array
    {
        return $this->timeSegmentValues();
    }

    /**
     * Join the given segment values for display.
     *
     * @param array<string, string> $segments
     */
    public function formatSegments(array $segments): string
    {
        return $this->formatTimeSegments($segments);
    }

    /**
     * Get the focusable segments, in order.
     *
     * @return list<string>
     */
    protected function segments(): array
    {
        return $this->timeSegments();
    }

    /**
     * Step the focused segment up or down.
     */
    protected function stepSegment(int $step): void
    {
        $this->stepTime($step);
    }

    /**
     * Build the time with the edited segment applied, or null when it is invalid.
     */
    protected function segmentDate(): ?DateTimeImmutable
    {
        return $this->timeCandidate($this->date);
    }

    /**
     * Build a bound given as a clock time alone from its fields, since it applies on any date.
     *
     * Parsing it would place it on the current date, where daylight saving could move it.
     */
    protected function normalizeBound(DateTimeInterface|string|null $bound): ?DateTimeImmutable
    {
        if (is_string($bound)) {
            $parsed = date_parse($bound);

            if ($parsed['error_count'] === 0 && $parsed['warning_count'] === 0 && $parsed['hour'] !== false
                && $parsed['year'] === false && $parsed['month'] === false && $parsed['day'] === false && ! isset($parsed['relative'])) {
                $bound = (new DateTimeImmutable('@0'))->setTime($parsed['hour'], $parsed['minute'], $parsed['second']);
            }
        }

        return parent::normalizeBound($bound);
    }

    /**
     * Compare clock times only, so the bounds apply on any date.
     */
    protected function compare(DateTimeImmutable $a, DateTimeImmutable $b): int
    {
        return $a->format('H:i:s') <=> $b->format('H:i:s');
    }

    /**
     * Move the time onto the bound's clock time while keeping its own date.
     */
    protected function clamp(DateTimeImmutable $time): DateTimeImmutable
    {
        return $this->clampedTime($time)
            ?? throw new InvalidArgumentException('Time [' . parent::clamp($time)->format('H:i:s') . '] cannot be represented on ' . $time->format('Y-m-d') . ' in ' . $time->getTimezone()->getName() . '.');
    }

    /**
     * Start on the time within the range, unless the bound's clock time doesn't exist on its date.
     *
     * Asking for input then fails, while a non-interactive prompt validates its default instead.
     */
    protected function startingValue(DateTimeImmutable $date): DateTimeImmutable
    {
        return $this->clampedTime($date) ?? $date;
    }

    /**
     * Move the time onto the bound's clock time, or return null when that clock time doesn't exist on its date.
     */
    protected function clampedTime(DateTimeImmutable $time): ?DateTimeImmutable
    {
        $bound = parent::clamp($time);

        if ($bound === $time) {
            return $time;
        }

        return $this->timeAt($time, (int) $bound->format('G'), (int) $bound->format('i'), (int) $bound->format('s'));
    }

    /**
     * Render the prompt, starting from a time within the range.
     */
    protected function render(): void
    {
        if ($this->state === 'initial') {
            $this->goTo($this->date);
        }

        parent::render();
    }

    /**
     * Get the format used when displaying times in messages.
     */
    protected function displayFormat(): string
    {
        return $this->timeFormat();
    }
}
