<?php

declare(strict_types=1);

namespace Hypervel\Support;

use Carbon\CarbonInterval;
use Closure;
use DateInterval;
use Hyperf\Collection\Collection;
use Hyperf\Macroable\Macroable;
use PHPUnit\Framework\Assert as PHPUnit;
use RuntimeException;

use function Hyperf\Support\value;
use function Hyperf\Tappable\tap;

class Sleep
{
    use Macroable;

    /**
     * The fake sleep callbacks.
     */
    public static array $fakeSleepCallbacks = [];

    /**
     * Keep Carbon's "now" in sync when sleeping.
     */
    protected static bool $syncWithCarbon = false;

    /**
     * The total duration to sleep.
     *
     * @var CarbonInterval
     */
    public $duration;

    /**
     * The callback that determines if sleeping should continue.
     */
    public ?Closure $while = null;

    /**
     * The pending duration to sleep.
     */
    protected null|float|int $pending = null;

    /**
     * Indicates that all sleeping should be faked.
     */
    protected static bool $fake = false;

    /**
     * The sequence of sleep durations encountered while faking.
     *
     * @var array<int, CarbonInterval>
     */
    protected static array $sequence = [];

    /**
     * Indicates if the instance should sleep.
     */
    protected bool $shouldSleep = true;

    /**
     * Indicates if the instance already slept via `then()`.
     */
    protected bool $alreadySlept = false;

    /**
     * Create a new class instance.
     */
    public function __construct(DateInterval|float|int $duration)
    {
        $this->duration($duration);
    }

    /**
     * Sleep for the given duration.
     */
    public static function for(DateInterval|float|int $duration): static
    {
        return new static($duration);
    }

    /**
     * Sleep until the given timestamp.
     */
    public static function until(DateInterval|float|int|string $timestamp): static
    {
        if (is_numeric($timestamp)) {
            $timestamp = Carbon::createFromTimestamp($timestamp, date_default_timezone_get());
        }

        return new static(Carbon::now()->diff($timestamp));
    }

    /**
     * Sleep for the given number of microseconds.
     */
    public static function usleep(int $duration): static
    {
        return (new static($duration))->microseconds();
    }

    /**
     * Sleep for the given number of seconds.
     */
    public static function sleep(float|int $duration): static
    {
        return (new static($duration))->seconds();
    }

    /**
     * Sleep for the given duration. Replaces any previously defined duration.
     */
    protected function duration(DateInterval|float|int $duration): static
    {
        if (! $duration instanceof DateInterval) {
            $this->duration = CarbonInterval::microsecond(0);

            $this->pending = $duration;
        } else {
            $duration = CarbonInterval::instance($duration);

            if ($duration->totalMicroseconds < 0) {
                $duration = CarbonInterval::seconds(0);
            }

            $this->duration = $duration;
            $this->pending = null;
        }

        return $this;
    }

    /**
     * Sleep for the given number of minutes.
     */
    public function minutes(): static
    {
        $this->duration->add('minutes', $this->pullPending());

        return $this;
    }

    /**
     * Sleep for one minute.
     */
    public function minute(): static
    {
        return $this->minutes();
    }

    /**
     * Sleep for the given number of seconds.
     */
    public function seconds(): static
    {
        $this->duration->add('seconds', $this->pullPending());

        return $this;
    }

    /**
     * Sleep for one second.
     */
    public function second(): static
    {
        return $this->seconds();
    }

    /**
     * Sleep for the given number of milliseconds.
     */
    public function milliseconds(): static
    {
        $this->duration->add('milliseconds', $this->pullPending());

        return $this;
    }

    /**
     * Sleep for one millisecond.
     */
    public function millisecond(): static
    {
        return $this->milliseconds();
    }

    /**
     * Sleep for the given number of microseconds.
     */
    public function microseconds(): static
    {
        $this->duration->add('microseconds', $this->pullPending());

        return $this;
    }

    /**
     * Sleep for on microsecond.
     */
    public function microsecond(): static
    {
        return $this->microseconds();
    }

    /**
     * Add additional time to sleep for.
     */
    public function and(float|int $duration): static
    {
        $this->pending = $duration;

        return $this;
    }

    /**
     * Sleep while a given callback returns "true".
     */
    public function while(Closure $callback): static
    {
        $this->while = $callback;

        return $this;
    }

    /**
     * Specify a callback that should be executed after sleeping.
     */
    public function then(callable $then): mixed
    {
        $this->goodnight();

        $this->alreadySlept = true;

        return $then();
    }

    /**
     * Handle the object's destruction.
     */
    public function __destruct()
    {
        $this->goodnight();
    }

    /**
     * Handle the object's destruction.
     */
    protected function goodnight(): void
    {
        if ($this->alreadySlept || ! $this->shouldSleep) {
            return;
        }

        if ($this->pending !== null) {
            throw new RuntimeException('Unknown duration unit.');
        }

        if (static::$fake) {
            static::$sequence[] = $this->duration;

            if (static::$syncWithCarbon) {
                Carbon::setTestNow(Carbon::now()->add($this->duration));
            }

            foreach (static::$fakeSleepCallbacks as $callback) {
                $callback($this->duration);
            }

            return;
        }

        $remaining = $this->duration->copy();

        $seconds = (int) $remaining->totalSeconds;

        $while = $this->while ?: function () {
            static $return = [true, false];

            return array_shift($return);
        };

        while ($while()) {
            if ($seconds > 0) {
                sleep($seconds);

                $remaining = $remaining->subSeconds($seconds);
            }

            $microseconds = (int) $remaining->totalMicroseconds;

            if ($microseconds > 0) {
                usleep($microseconds);
            }
        }
    }

    /**
     * Resolve the pending duration.
     */
    protected function pullPending(): float|int
    {
        if ($this->pending === null) {
            $this->shouldNotSleep();

            throw new RuntimeException('No duration specified.');
        }

        if ($this->pending < 0) {
            $this->pending = 0;
        }

        return tap($this->pending, function () {
            $this->pending = null;
        });
    }

    /**
     * Stay awake and capture any attempts to sleep.
     */
    public static function fake(bool $value = true, bool $syncWithCarbon = false): void
    {
        static::$fake = $value;

        static::$sequence = [];
        static::$fakeSleepCallbacks = [];
        static::$syncWithCarbon = $syncWithCarbon;
    }

    /**
     * Assert a given amount of sleeping occurred a specific number of times.
     */
    public static function assertSlept(Closure $expected, int $times = 1): void
    {
        $count = (new Collection(static::$sequence))->filter($expected)->count();

        PHPUnit::assertSame(
            $times,
            $count,
            "The expected sleep was found [{$count}] times instead of [{$times}]."
        );
    }

    /**
     * Assert sleeping occurred a given number of times.
     */
    public static function assertSleptTimes(int $expected): void
    {
        PHPUnit::assertSame($expected, $count = count(static::$sequence), "Expected [{$expected}] sleeps but found [{$count}].");
    }

    /**
     * Assert the given sleep sequence was encountered.
     */
    public static function assertSequence(array $sequence): void
    {
        static::assertSleptTimes(count($sequence));

        (new Collection($sequence))
            ->zip(static::$sequence)
            ->eachSpread(function (?Sleep $expected, CarbonInterval $actual) {
                if ($expected === null) {
                    return;
                }

                PHPUnit::assertTrue(
                    $expected->shouldNotSleep()->duration->equalTo($actual),
                    vsprintf('Expected sleep duration of [%s] but actually slept for [%s].', [
                        $expected->duration->cascade()->forHumans([
                            'options' => 0,
                            'minimumUnit' => 'microsecond',
                        ]),
                        $actual->cascade()->forHumans([
                            'options' => 0,
                            'minimumUnit' => 'microsecond',
                        ]),
                    ])
                );
            });
    }

    /**
     * Assert that no sleeping occurred.
     */
    public static function assertNeverSlept(): void
    {
        static::assertSleptTimes(0);
    }

    /**
     * Assert that no sleeping occurred.
     */
    public static function assertInsomniac(): void
    {
        if (static::$sequence === []) {
            PHPUnit::assertTrue(true);
        }

        foreach (static::$sequence as $duration) {
            PHPUnit::assertSame(0, (int) $duration->totalMicroseconds, vsprintf('Unexpected sleep duration of [%s] found.', [
                $duration->cascade()->forHumans([
                    'options' => 0,
                    'minimumUnit' => 'microsecond',
                ]),
            ]));
        }
    }

    /**
     * Indicate that the instance should not sleep.
     */
    protected function shouldNotSleep(): static
    {
        $this->shouldSleep = false;

        return $this;
    }

    /**
     * Only sleep when the given condition is true.
     */
    public function when(bool|Closure $condition): static
    {
        $this->shouldSleep = (bool) value($condition, $this);

        return $this;
    }

    /**
     * Don't sleep when the given condition is true.
     */
    public function unless(bool|Closure $condition): static
    {
        return $this->when(! value($condition, $this));
    }

    /**
     * Specify a callback that should be invoked when faking sleep within a test.
     */
    public static function whenFakingSleep(callable $callback): void
    {
        static::$fakeSleepCallbacks[] = $callback;
    }

    /**
     * Indicate that Carbon's "now" should be kept in sync when sleeping.
     */
    public static function syncWithCarbon(bool $value = true): void
    {
        static::$syncWithCarbon = $value;
    }
}
