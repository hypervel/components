<?php

declare(strict_types=1);

namespace Hypervel\Support;

use Carbon\Carbon as BaseCarbon;
use Carbon\CarbonImmutable as BaseCarbonImmutable;
use Hypervel\Support\Traits\Conditionable;
use Hypervel\Support\Traits\Dumpable;
use InvalidArgumentException;
use Symfony\Component\Uid\TimeBasedUidInterface;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Uid\Uuid;

class Carbon extends BaseCarbon
{
    use Conditionable;
    use Dumpable;

    public static function setTestNow(mixed $testNow = null): void
    {
        BaseCarbon::setTestNow($testNow);
        BaseCarbonImmutable::setTestNow($testNow);
    }

    /**
     * Create a Carbon instance from a given time-based UUID or ULID.
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
     * Get the current date / time plus a given amount of time.
     */
    public function plus(
        int $years = 0,
        int $months = 0,
        int $weeks = 0,
        int $days = 0,
        int $hours = 0,
        int $minutes = 0,
        int $seconds = 0,
        int $microseconds = 0
    ): static {
        return $this->add("
            {$years} years {$months} months {$weeks} weeks {$days} days
            {$hours} hours {$minutes} minutes {$seconds} seconds {$microseconds} microseconds
        ");
    }

    /**
     * Get the current date / time minus a given amount of time.
     */
    public function minus(
        int $years = 0,
        int $months = 0,
        int $weeks = 0,
        int $days = 0,
        int $hours = 0,
        int $minutes = 0,
        int $seconds = 0,
        int $microseconds = 0
    ): static {
        return $this->sub("
            {$years} years {$months} months {$weeks} weeks {$days} days
            {$hours} hours {$minutes} minutes {$seconds} seconds {$microseconds} microseconds
        ");
    }
}
