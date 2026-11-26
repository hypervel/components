<?php

declare(strict_types=1);

namespace Hypervel\Prompts;

use DateTimeImmutable;

class DatePickerPrompt extends TimeBasedPrompt
{
    /**
     * Get the date being edited, including a valid segment that hasn't been committed yet.
     */
    public function previewDate(): DateTimeImmutable
    {
        if ($this->editingSegment && $this->segmentError() === null) {
            return $this->segmentDate() ?? $this->date;
        }

        return $this->date;
    }

    /**
     * Get the display value of each segment, keyed by segment name.
     *
     * @return array<string, string>
     */
    public function segmentValues(): array
    {
        return [
            'year' => $this->segmentDisplay('year', $this->date->format('Y')),
            'month' => $this->segmentDisplay('month', $this->date->format('m')),
            'day' => $this->segmentDisplay('day', $this->date->format('d')),
        ];
    }

    /**
     * Join the given segment values for display.
     *
     * @param array<string, string> $segments
     */
    public function formatSegments(array $segments): string
    {
        return implode('-', $segments);
    }

    /**
     * Get the focusable segments, in order.
     *
     * @return list<string>
     */
    protected function segments(): array
    {
        return ['year', 'month', 'day'];
    }

    /**
     * Step the focused segment up or down.
     */
    protected function stepSegment(int $step): void
    {
        if (! $this->commitSegment()) {
            return;
        }

        $date = match ($this->focused) {
            'year' => $this->addMonths($step * 12),
            'month' => $this->addMonths($step),
            default => $this->date->modify(sprintf('%+d days', $step)),
        };

        if ((int) $date->format('Y') >= 1 && (int) $date->format('Y') <= 9999) {
            $this->goTo($date);
        }
    }

    /**
     * Build the date with the edited segment applied, or null when it is invalid.
     */
    protected function segmentDate(): ?DateTimeImmutable
    {
        $year = (int) $this->date->format('Y');
        $month = (int) $this->date->format('m');
        $day = (int) $this->date->format('d');

        match ($this->focused) {
            'year' => $year = (int) $this->segmentBuffer,
            'month' => $month = (int) $this->segmentBuffer,
            'day' => $day = (int) $this->segmentBuffer,
            default => null,
        };

        if ($year < 1 || $year > 9999 || $month < 1 || $month > 12) {
            return null;
        }

        if ($this->focused !== 'day') {
            $day = min($day, (int) $this->date->setDate($year, $month, 1)->format('t'));
        }

        return checkdate($month, $day, $year) ? $this->date->setDate($year, $month, $day) : null;
    }

    /**
     * Add the given number of months, clamping the day to the target month.
     */
    protected function addMonths(int $months): DateTimeImmutable
    {
        $month = $this->date->modify('first day of this month')->modify(sprintf('%+d months', $months));

        $day = min((int) $this->date->format('j'), (int) $month->format('t'));

        return $month->setDate((int) $month->format('Y'), (int) $month->format('n'), $day);
    }

    /**
     * Get the date to start on when there is no default.
     */
    protected function initialDate(): DateTimeImmutable
    {
        return new DateTimeImmutable('today');
    }

    /**
     * Truncate the time to the start of the day.
     */
    protected function truncateTime(DateTimeImmutable $date): DateTimeImmutable
    {
        return $date->setTime(0, 0);
    }

    /**
     * Get the format used when displaying dates in messages.
     */
    protected function displayFormat(): string
    {
        return 'Y-m-d';
    }
}
