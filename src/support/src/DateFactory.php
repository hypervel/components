<?php

declare(strict_types=1);

namespace Hypervel\Support;

use Carbon\CarbonInterface;
use Carbon\Factory;
use Closure;
use InvalidArgumentException;
use ReflectionClass;

/**
 * @see https://carbon.nesbot.com/docs/
 * @see https://github.com/briannesbitt/Carbon/blob/master/src/Carbon/Factory.php
 *
 * @method bool canBeCreatedFromFormat(?string $date, string $format)
 * @method null|\Carbon\CarbonInterface create($year = 0, $month = 1, $day = 1, $hour = 0, $minute = 0, $second = 0, $timezone = null)
 * @method \Carbon\CarbonInterface createFromDate($year = null, $month = null, $day = null, $timezone = null)
 * @method null|\Carbon\CarbonInterface createFromFormat($format, $time, $timezone = null)
 * @method null|\Carbon\CarbonInterface createFromIsoFormat(string $format, string $time, $timezone = null, ?string $locale = 'en', ?\Symfony\Contracts\Translation\TranslatorInterface $translator = null)
 * @method null|\Carbon\CarbonInterface createFromLocaleFormat(string $format, string $locale, string $time, $timezone = null)
 * @method null|\Carbon\CarbonInterface createFromLocaleIsoFormat(string $format, string $locale, string $time, $timezone = null)
 * @method \Carbon\CarbonInterface createFromTime($hour = 0, $minute = 0, $second = 0, $timezone = null)
 * @method \Carbon\CarbonInterface createFromTimeString(string $time, \DateTimeZone|string|int|null $timezone = null)
 * @method \Carbon\CarbonInterface createFromTimestamp(string|int|float $timestamp, \DateTimeZone|string|int|null $timezone = null)
 * @method \Carbon\CarbonInterface createFromTimestampMs(string|int|float $timestamp, \DateTimeZone|string|int|null $timezone = null)
 * @method \Carbon\CarbonInterface createFromTimestampMsUTC($timestamp)
 * @method \Carbon\CarbonInterface createFromTimestampUTC(float|int|string $timestamp)
 * @method \Carbon\CarbonInterface createMidnightDate($year = null, $month = null, $day = null, $timezone = null)
 * @method null|\Carbon\CarbonInterface createSafe($year = null, $month = null, $day = null, $hour = null, $minute = null, $second = null, $timezone = null)
 * @method \Carbon\CarbonInterface createStrict(?int $year = 0, ?int $month = 1, ?int $day = 1, ?int $hour = 0, ?int $minute = 0, ?int $second = 0, $timezone = null)
 * @method void disableHumanDiffOption($humanDiffOption)
 * @method void enableHumanDiffOption($humanDiffOption)
 * @method mixed executeWithLocale(string $locale, callable $func)
 * @method \Carbon\CarbonInterface fromSerialized($value)
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
 * @method null|\Carbon\CarbonInterface getTestNow()
 * @method \Symfony\Contracts\Translation\TranslatorInterface getTranslator()
 * @method int getWeekEndsAt(?string $locale = null)
 * @method int getWeekStartsAt(?string $locale = null)
 * @method array getWeekendDays()
 * @method bool hasFormat(string $date, string $format)
 * @method bool hasFormatWithModifiers(string $date, string $format)
 * @method bool hasMacro($name)
 * @method bool hasRelativeKeywords(?string $time)
 * @method bool hasTestNow()
 * @method \Carbon\CarbonInterface instance(\DateTimeInterface $date)
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
 * @method null|\Carbon\CarbonInterface make($var, \DateTimeZone|string|null $timezone = null)
 * @method void mixin(object|string $mixin)
 * @method \Carbon\CarbonInterface now(\DateTimeZone|string|int|null $timezone = null)
 * @method \Carbon\CarbonInterface parse(\DateTimeInterface|\Carbon\WeekDay|\Carbon\Month|string|int|float|null $time, \DateTimeZone|string|int|null $timezone = null)
 * @method \Carbon\CarbonInterface parseFromLocale(string $time, ?string $locale = null, \DateTimeZone|string|int|null $timezone = null)
 * @method string pluralUnit(string $unit)
 * @method null|\Carbon\CarbonInterface rawCreateFromFormat(string $format, string $time, $timezone = null)
 * @method \Carbon\CarbonInterface rawParse(\DateTimeInterface|\Carbon\WeekDay|\Carbon\Month|string|int|float|null $time, \DateTimeZone|string|int|null $timezone = null)
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
 * @method void setToStringFormat(null|Closure|string $format)
 * @method void setTranslator(\Symfony\Contracts\Translation\TranslatorInterface $translator)
 * @method void setWeekendDays($days)
 * @method bool shouldOverflowMonths()
 * @method bool shouldOverflowYears()
 * @method string singularUnit(string $unit)
 * @method void sleep(float|int $seconds)
 * @method \Carbon\CarbonInterface today(\DateTimeZone|string|int|null $timezone = null)
 * @method \Carbon\CarbonInterface tomorrow(\DateTimeZone|string|int|null $timezone = null)
 * @method string translateTimeString(string $timeString, ?string $from = null, ?string $to = null, int $mode = \Carbon\CarbonInterface::TRANSLATE_ALL)
 * @method string translateWith(\Symfony\Contracts\Translation\TranslatorInterface $translator, string $key, array $parameters = [], $number = null)
 * @method void useMonthsOverflow($monthsOverflow = true)
 * @method void useStrictMode($strictModeEnabled = true)
 * @method void useYearsOverflow($yearsOverflow = true)
 * @method mixed withTestNow(mixed $testNow, callable $callback)
 * @method Factory withTimeZone(null|\DateTimeZone|int|string $timezone)
 * @method \Carbon\CarbonInterface yesterday(\DateTimeZone|string|int|null $timezone = null)
 */
class DateFactory
{
    /**
     * The default class that will be used for all created dates.
     *
     * @var class-string<CarbonInterface>
     */
    public const string DEFAULT_CLASS_NAME = CarbonImmutable::class;

    /**
     * The type (class) of dates that should be created.
     *
     * @var null|class-string<CarbonInterface>
     */
    protected static ?string $dateClass = null;

    /**
     * This callable may be used to intercept date creation.
     */
    protected static ?Closure $callable = null;

    /**
     * The Carbon factory that should be used when creating dates.
     */
    protected static ?Factory $factory = null;

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
        if ($handler instanceof Factory) {
            static::useFactory($handler);
            return;
        }

        if (is_string($handler) && is_a($handler, CarbonInterface::class, true)) {
            static::useClass($handler);
            return;
        }

        if (is_callable($handler)) {
            static::useCallable($handler);
            return;
        }

        throw new InvalidArgumentException(
            'Invalid date creation handler. Please provide a Carbon class, callable, or Carbon factory.'
        );
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
        static::$callable = Closure::fromCallable($callable);

        static::$dateClass = null;
        static::$factory = null;
    }

    /**
     * Use the given date type (class) when generating dates.
     *
     * Boot-only. The class name persists in a static property for the worker
     * lifetime and is used for every date creation across all coroutines.
     *
     * @param class-string<CarbonInterface> $dateClass
     */
    public static function useClass(string $dateClass): void
    {
        if (
            ! is_a($dateClass, CarbonInterface::class, true)
            || ! (new ReflectionClass($dateClass))->isInstantiable()
        ) {
            throw new InvalidArgumentException(
                'The date class must be an instantiable CarbonInterface implementation.'
            );
        }

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
    public static function useFactory(Factory $factory): void
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
     */
    public function __call(string $method, array $parameters): mixed
    {
        $defaultClassName = static::DEFAULT_CLASS_NAME;

        if (static::$callable !== null) {
            return (static::$callable)($defaultClassName::$method(...$parameters));
        }

        if (static::$factory !== null) {
            return static::$factory->{$method}(...$parameters);
        }

        $dateClass = static::$dateClass ?? $defaultClassName;

        if (
            method_exists($dateClass, $method)
            || $dateClass::hasMacro($method)
        ) {
            return $dateClass::$method(...$parameters);
        }

        return $dateClass::instance($defaultClassName::$method(...$parameters));
    }
}
