<?php

declare(strict_types=1);

namespace Hypervel\Support;

use Carbon\Factory;
use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * @see https://carbon.nesbot.com/docs/
 * @see https://github.com/briannesbitt/Carbon/blob/master/src/Carbon/Factory.php
 *
 * @method bool canBeCreatedFromFormat(?string $date, string $format)
 * @method null|\Hypervel\Support\Carbon create($year = 0, $month = 1, $day = 1, $hour = 0, $minute = 0, $second = 0, $timezone = null)
 * @method \Hypervel\Support\Carbon createFromDate($year = null, $month = null, $day = null, $timezone = null)
 * @method null|\Hypervel\Support\Carbon createFromFormat($format, $time, $timezone = null)
 * @method null|\Hypervel\Support\Carbon createFromIsoFormat(string $format, string $time, $timezone = null, ?string $locale = 'en', ?\Symfony\Contracts\Translation\TranslatorInterface $translator = null)
 * @method null|\Hypervel\Support\Carbon createFromLocaleFormat(string $format, string $locale, string $time, $timezone = null)
 * @method null|\Hypervel\Support\Carbon createFromLocaleIsoFormat(string $format, string $locale, string $time, $timezone = null)
 * @method \Hypervel\Support\Carbon createFromTime($hour = 0, $minute = 0, $second = 0, $timezone = null)
 * @method \Hypervel\Support\Carbon createFromTimeString(string $time, \DateTimeZone|string|int|null $timezone = null)
 * @method \Hypervel\Support\Carbon createFromTimestamp(string|int|float $timestamp, \DateTimeZone|string|int|null $timezone = null)
 * @method \Hypervel\Support\Carbon createFromTimestampMs(string|int|float $timestamp, \DateTimeZone|string|int|null $timezone = null)
 * @method \Hypervel\Support\Carbon createFromTimestampMsUTC($timestamp)
 * @method \Hypervel\Support\Carbon createFromTimestampUTC(float|int|string $timestamp)
 * @method \Hypervel\Support\Carbon createMidnightDate($year = null, $month = null, $day = null, $timezone = null)
 * @method null|\Hypervel\Support\Carbon createSafe($year = null, $month = null, $day = null, $hour = null, $minute = null, $second = null, $timezone = null)
 * @method \Hypervel\Support\Carbon createStrict(?int $year = 0, ?int $month = 1, ?int $day = 1, ?int $hour = 0, ?int $minute = 0, ?int $second = 0, $timezone = null)
 * @method void disableHumanDiffOption($humanDiffOption)
 * @method void enableHumanDiffOption($humanDiffOption)
 * @method mixed executeWithLocale(string $locale, callable $func)
 * @method \Hypervel\Support\Carbon fromSerialized($value)
 * @method array getAvailableLocales()
 * @method array getAvailableLocalesInfo()
 * @method array getDays()
 * @method null|string getFallbackLocale()
 * @method array getFormatsToIsoReplacements()
 * @method int getHumanDiffOptions()
 * @method array getIsoUnits()
 * @method array|false getLastErrors()
 * @method string getLocale()
 * @method int getMidDayAt()
 * @method string getTimeFormatByPrecision(string $unitPrecision)
 * @method null|Closure|string getTranslationMessageWith($translator, string $key, ?string $locale = null, ?string $default = null)
 * @method null|\Hypervel\Support\Carbon getTestNow()
 * @method \Symfony\Contracts\Translation\TranslatorInterface getTranslator()
 * @method int getWeekEndsAt(?string $locale = null)
 * @method int getWeekStartsAt(?string $locale = null)
 * @method array getWeekendDays()
 * @method bool hasFormat(string $date, string $format)
 * @method bool hasFormatWithModifiers(string $date, string $format)
 * @method bool hasMacro($name)
 * @method bool hasRelativeKeywords(?string $time)
 * @method bool hasTestNow()
 * @method \Hypervel\Support\Carbon instance(\DateTimeInterface $date)
 * @method bool isImmutable()
 * @method bool isModifiableUnit($unit)
 * @method bool isMutable()
 * @method bool isStrictModeEnabled()
 * @method bool localeHasDiffOneDayWords(string $locale)
 * @method bool localeHasDiffSyntax(string $locale)
 * @method bool localeHasDiffTwoDayWords(string $locale)
 * @method bool localeHasPeriodSyntax($locale)
 * @method bool localeHasShortUnits(string $locale)
 * @method void macro(string $name, ?callable $macro)
 * @method null|\Hypervel\Support\Carbon make($var, \DateTimeZone|string|null $timezone = null)
 * @method void mixin(object|string $mixin)
 * @method \Hypervel\Support\Carbon now(\DateTimeZone|string|int|null $timezone = null)
 * @method \Hypervel\Support\Carbon parse(\DateTimeInterface|\Carbon\WeekDay|\Carbon\Month|string|int|float|null $time, \DateTimeZone|string|int|null $timezone = null)
 * @method \Hypervel\Support\Carbon parseFromLocale(string $time, ?string $locale = null, \DateTimeZone|string|int|null $timezone = null)
 * @method string pluralUnit(string $unit)
 * @method null|\Hypervel\Support\Carbon rawCreateFromFormat(string $format, string $time, $timezone = null)
 * @method \Hypervel\Support\Carbon rawParse(\DateTimeInterface|\Carbon\WeekDay|\Carbon\Month|string|int|float|null $time, \DateTimeZone|string|int|null $timezone = null)
 * @method void resetMonthsOverflow()
 * @method void resetToStringFormat()
 * @method void resetYearsOverflow()
 * @method void serializeUsing($callback)
 * @method void setHumanDiffOptions($humanDiffOptions)
 * @method void setFallbackLocale(string $locale)
 * @method void setLocale(string $locale)
 * @method void setMidDayAt($hour)
 * @method void setTestNow(mixed $testNow = null)
 * @method void setTestNowAndTimezone(mixed $testNow = null, $timezone = null)
 * @method void setToStringFormat(null|\Closure|string $format)
 * @method void setTranslator(\Symfony\Contracts\Translation\TranslatorInterface $translator)
 * @method void setWeekEndsAt($day)
 * @method void setWeekStartsAt($day)
 * @method void setWeekendDays($days)
 * @method bool shouldOverflowMonths()
 * @method bool shouldOverflowYears()
 * @method string singularUnit(string $unit)
 * @method void sleep(float|int $seconds)
 * @method \Hypervel\Support\Carbon today(\DateTimeZone|string|int|null $timezone = null)
 * @method \Hypervel\Support\Carbon tomorrow(\DateTimeZone|string|int|null $timezone = null)
 * @method string translateTimeString(string $timeString, ?string $from = null, ?string $to = null, int $mode = \Carbon\CarbonInterface::TRANSLATE_ALL)
 * @method string translateWith(\Symfony\Contracts\Translation\TranslatorInterface $translator, string $key, array $parameters = [], $number = null)
 * @method void useMonthsOverflow($monthsOverflow = true)
 * @method void useStrictMode($strictModeEnabled = true)
 * @method void useYearsOverflow($yearsOverflow = true)
 * @method mixed withTestNow(mixed $testNow, callable $callback)
 * @method static withTimeZone(null|\DateTimeZone|int|string $timezone)
 * @method \Hypervel\Support\Carbon yesterday(\DateTimeZone|string|int|null $timezone = null)
 */
class DateFactory
{
    /**
     * The default class that will be used for all created dates.
     *
     * @var string
     */
    public const DEFAULT_CLASS_NAME = Carbon::class;

    /**
     * The type (class) of dates that should be created.
     */
    protected static ?string $dateClass = null;

    /**
     * This callable may be used to intercept date creation.
     *
     * @var null|callable
     */
    protected static $callable;

    /**
     * The Carbon factory that should be used when creating dates.
     */
    protected static ?object $factory = null;

    /**
     * Use the given handler when generating dates (class name, callable, or factory).
     *
     * Boot-only. The selected handler persists in static state for the worker
     * lifetime and affects date creation in every subsequent request.
     *
     * @throws InvalidArgumentException
     */
    public static function use(mixed $handler): void
    {
        if (is_callable($handler) && is_object($handler)) {
            static::useCallable($handler);
            return;
        }
        if (is_string($handler)) {
            static::useClass($handler);
            return;
        }
        if ($handler instanceof Factory) {
            static::useFactory($handler);
            return;
        }

        throw new InvalidArgumentException('Invalid date creation handler. Please provide a class name, callable, or Carbon factory.');
    }

    /**
     * Use the default date class when generating dates.
     *
     * Boot-only. Clearing the worker-wide handler affects date creation in
     * every subsequent request.
     */
    public static function useDefault(): void
    {
        static::$dateClass = null;
        static::$callable = null;
        static::$factory = null;
    }

    /**
     * Execute the given callable on each date creation.
     *
     * Boot-only. The callable persists in a static property for the worker
     * lifetime and runs on every date creation across all coroutines.
     */
    public static function useCallable(callable $callable): void
    {
        static::$callable = $callable;

        static::$dateClass = null;
        static::$factory = null;
    }

    /**
     * Use the given date type (class) when generating dates.
     *
     * Boot-only. The class name persists in a static property for the worker
     * lifetime and is used for every date creation across all coroutines.
     */
    public static function useClass(string $dateClass): void
    {
        static::$dateClass = $dateClass;

        static::$factory = null;
        static::$callable = null;
    }

    /**
     * Use the given Carbon factory when generating dates.
     *
     * Boot-only. The factory persists in a static property for the worker
     * lifetime and is used for every date creation across all coroutines.
     */
    public static function useFactory(object $factory): void
    {
        static::$factory = $factory;

        static::$dateClass = null;
        static::$callable = null;
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::useDefault();
    }

    /**
     * Handle dynamic calls to generate dates.
     *
     * @throws RuntimeException
     */
    public function __call(string $method, array $parameters)
    {
        $defaultClassName = static::DEFAULT_CLASS_NAME;

        // Using callable to generate dates...
        if (static::$callable) {
            return call_user_func(static::$callable, $defaultClassName::$method(...$parameters));
        }

        // Using Carbon factory to generate dates...
        if (static::$factory) {
            return static::$factory->{$method}(...$parameters);
        }

        $dateClass = static::$dateClass ?: $defaultClassName;

        // Check if the date can be created using the public class method...
        if (
            method_exists($dateClass, $method)
            || method_exists($dateClass, 'hasMacro') && $dateClass::hasMacro($method)
        ) {
            return $dateClass::$method(...$parameters);
        }

        // If that fails, create the date with the default class...
        $date = $defaultClassName::$method(...$parameters);

        // If the configured class has an "instance" method, we'll try to pass our date into there...
        if (method_exists($dateClass, 'instance')) {
            return $dateClass::instance($date);
        }

        // Otherwise, assume the configured class has a DateTime compatible constructor...
        return new $dateClass($date->format('Y-m-d H:i:s.u'), $date->getTimezone());
    }
}
