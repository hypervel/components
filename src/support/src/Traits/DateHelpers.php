<?php

declare(strict_types=1);

namespace Hypervel\Support\Traits;

use InvalidArgumentException;
use Symfony\Component\Uid\TimeBasedUidInterface;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Uid\Uuid;

trait DateHelpers
{
    use Conditionable;
    use Dumpable;

    /**
     * Create a date instance from a given time-based UUID or ULID.
     */
    public static function createFromId(Uuid|Ulid|string $id): static
    {
        if (is_string($id)) {
            $id = Ulid::isValid($id) ? Ulid::fromString($id) : Uuid::fromString($id);
        }

        if (! $id instanceof TimeBasedUidInterface) {
            throw new InvalidArgumentException(
                'The given UUID is not time-based and cannot be converted to a date.'
            );
        }

        return static::createFromInterface($id->getDateTime());
    }

    /**
     * Get the date / time plus a given amount of time.
     */
    public function plus(
        int $years = 0,
        int $months = 0,
        int $weeks = 0,
        int $days = 0,
        int $hours = 0,
        int $minutes = 0,
        int $seconds = 0,
        int $microseconds = 0,
        ?bool $overflow = null
    ): static {
        $date = $this;

        // Zero-unit operations also clone immutable dates.
        if ($years !== 0) {
            $date = $date->add('years', $years, $overflow);
        }

        if ($months !== 0) {
            $date = $date->add('months', $months, $overflow);
        }

        return $date->add("
            {$weeks} weeks {$days} days
            {$hours} hours {$minutes} minutes {$seconds} seconds {$microseconds} microseconds
        ");
    }

    /**
     * Get the date / time minus a given amount of time.
     */
    public function minus(
        int $years = 0,
        int $months = 0,
        int $weeks = 0,
        int $days = 0,
        int $hours = 0,
        int $minutes = 0,
        int $seconds = 0,
        int $microseconds = 0,
        ?bool $overflow = null
    ): static {
        $date = $this;

        if ($years !== 0) {
            $date = $date->sub('years', $years, $overflow);
        }

        if ($months !== 0) {
            $date = $date->sub('months', $months, $overflow);
        }

        return $date->sub("
            {$weeks} weeks {$days} days
            {$hours} hours {$minutes} minutes {$seconds} seconds {$microseconds} microseconds
        ");
    }
}
