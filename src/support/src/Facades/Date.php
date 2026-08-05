<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Closure;
use Hypervel\Support\DateFactory;

/**
 * @see https://carbon.nesbot.com/docs/
 * @see https://github.com/briannesbitt/Carbon/blob/master/src/Carbon/Factory.php
 *
 * @method static void use(mixed $handler)
 * @method static void useDefault()
 * @method static void useCallable(callable $callable)
 * @method static void useClass(string $dateClass)
 * @method static void useFactory(\Carbon\Factory $factory)
 * @method static void flushState()
 * @method static bool canBeCreatedFromFormat(?string $date, string $format)
 * @method static null|\Carbon\CarbonInterface create(mixed $year = 0, mixed $month = 1, mixed $day = 1, mixed $hour = 0, mixed $minute = 0, mixed $second = 0, mixed $timezone = null)
 * @method static \Carbon\CarbonInterface createFromDate(mixed $year = null, mixed $month = null, mixed $day = null, mixed $timezone = null)
 * @method static null|\Carbon\CarbonInterface createFromFormat(mixed $format, mixed $time, mixed $timezone = null)
 * @method static null|\Carbon\CarbonInterface createFromIsoFormat(string $format, string $time, mixed $timezone = null, ?string $locale = 'en', ?\Symfony\Contracts\Translation\TranslatorInterface $translator = null)
 * @method static null|\Carbon\CarbonInterface createFromLocaleFormat(string $format, string $locale, string $time, mixed $timezone = null)
 * @method static null|\Carbon\CarbonInterface createFromLocaleIsoFormat(string $format, string $locale, string $time, mixed $timezone = null)
 * @method static \Carbon\CarbonInterface createFromTime(mixed $hour = 0, mixed $minute = 0, mixed $second = 0, mixed $timezone = null)
 * @method static \Carbon\CarbonInterface createFromTimeString(string $time, \DateTimeZone|string|int|null $timezone = null)
 * @method static \Carbon\CarbonInterface createFromTimestamp(string|int|float $timestamp, \DateTimeZone|string|int|null $timezone = null)
 * @method static \Carbon\CarbonInterface createFromTimestampMs(string|int|float $timestamp, \DateTimeZone|string|int|null $timezone = null)
 * @method static \Carbon\CarbonInterface createFromTimestampMsUTC(mixed $timestamp)
 * @method static \Carbon\CarbonInterface createFromTimestampUTC(float|int|string $timestamp)
 * @method static \Carbon\CarbonInterface createMidnightDate(mixed $year = null, mixed $month = null, mixed $day = null, mixed $timezone = null)
 * @method static null|\Carbon\CarbonInterface createSafe(mixed $year = null, mixed $month = null, mixed $day = null, mixed $hour = null, mixed $minute = null, mixed $second = null, mixed $timezone = null)
 * @method static \Carbon\CarbonInterface createStrict(?int $year = 0, ?int $month = 1, ?int $day = 1, ?int $hour = 0, ?int $minute = 0, ?int $second = 0, mixed $timezone = null)
 * @method static void disableHumanDiffOption(mixed $humanDiffOption)
 * @method static void enableHumanDiffOption(mixed $humanDiffOption)
 * @method static mixed executeWithLocale(string $locale, callable $func)
 * @method static \Carbon\CarbonInterface fromSerialized(mixed $value)
 * @method static array getAvailableLocales()
 * @method static array getAvailableLocalesInfo()
 * @method static array getDays()
 * @method static null|string getFallbackLocale()
 * @method static array getFormatsToIsoReplacements()
 * @method static int getHumanDiffOptions()
 * @method static array getIsoUnits()
 * @method static array|false getLastErrors()
 * @method static string getLocale()
 * @method static int getMidDayAt()
 * @method static string getTimeFormatByPrecision(string $unitPrecision)
 * @method static null|Closure|string getTranslationMessageWith(mixed $translator, string $key, ?string $locale = null, ?string $default = null)
 * @method static null|\Carbon\CarbonInterface getTestNow()
 * @method static \Symfony\Contracts\Translation\TranslatorInterface getTranslator()
 * @method static int getWeekEndsAt(?string $locale = null)
 * @method static int getWeekStartsAt(?string $locale = null)
 * @method static array getWeekendDays()
 * @method static bool hasFormat(string $date, string $format)
 * @method static bool hasFormatWithModifiers(string $date, string $format)
 * @method static bool hasMacro(mixed $name)
 * @method static bool hasRelativeKeywords(?string $time)
 * @method static bool hasTestNow()
 * @method static \Carbon\CarbonInterface instance(\DateTimeInterface $date)
 * @method static bool isImmutable()
 * @method static bool isModifiableUnit(mixed $unit)
 * @method static bool isMutable()
 * @method static bool isStrictModeEnabled()
 * @method static bool localeHasDiffOneDayWords(string $locale)
 * @method static bool localeHasDiffSyntax(string $locale)
 * @method static bool localeHasDiffTwoDayWords(string $locale)
 * @method static bool localeHasPeriodSyntax(mixed $locale)
 * @method static bool localeHasShortUnits(string $locale)
 * @method static void macro(string $name, ?callable $macro)
 * @method static null|\Carbon\CarbonInterface make(mixed $var, \DateTimeZone|string|null $timezone = null)
 * @method static void mixin(object|string $mixin)
 * @method static \Carbon\CarbonInterface now(\DateTimeZone|string|int|null $timezone = null)
 * @method static \Carbon\CarbonInterface parse(\DateTimeInterface|\Carbon\WeekDay|\Carbon\Month|string|int|float|null $time, \DateTimeZone|string|int|null $timezone = null)
 * @method static \Carbon\CarbonInterface parseFromLocale(string $time, ?string $locale = null, \DateTimeZone|string|int|null $timezone = null)
 * @method static string pluralUnit(string $unit)
 * @method static null|\Carbon\CarbonInterface rawCreateFromFormat(string $format, string $time, mixed $timezone = null)
 * @method static \Carbon\CarbonInterface rawParse(\DateTimeInterface|\Carbon\WeekDay|\Carbon\Month|string|int|float|null $time, \DateTimeZone|string|int|null $timezone = null)
 * @method static void resetMonthsOverflow()
 * @method static void resetToStringFormat()
 * @method static void resetYearsOverflow()
 * @method static void serializeUsing(mixed $callback)
 * @method static void setHumanDiffOptions(mixed $humanDiffOptions)
 * @method static void setFallbackLocale(string $locale)
 * @method static void setLocale(string $locale)
 * @method static void setMidDayAt(mixed $hour)
 * @method static void setTestNow(mixed $testNow = null)
 * @method static void setTestNowAndTimezone(mixed $testNow = null, mixed $timezone = null)
 * @method static void setToStringFormat(null|Closure|string $format)
 * @method static void setTranslator(\Symfony\Contracts\Translation\TranslatorInterface $translator)
 * @method static void setWeekendDays(mixed $days)
 * @method static bool shouldOverflowMonths()
 * @method static bool shouldOverflowYears()
 * @method static string singularUnit(string $unit)
 * @method static void sleep(float|int $seconds)
 * @method static \Carbon\CarbonInterface today(\DateTimeZone|string|int|null $timezone = null)
 * @method static \Carbon\CarbonInterface tomorrow(\DateTimeZone|string|int|null $timezone = null)
 * @method static string translateTimeString(string $timeString, ?string $from = null, ?string $to = null, int $mode = \Carbon\CarbonInterface::TRANSLATE_ALL)
 * @method static string translateWith(\Symfony\Contracts\Translation\TranslatorInterface $translator, string $key, array $parameters = [], mixed $number = null)
 * @method static void useMonthsOverflow(mixed $monthsOverflow = true)
 * @method static void useStrictMode(mixed $strictModeEnabled = true)
 * @method static void useYearsOverflow(mixed $yearsOverflow = true)
 * @method static mixed withTestNow(mixed $testNow, callable $callback)
 * @method static \Carbon\Factory withTimeZone(null|\DateTimeZone|int|string $timezone)
 * @method static \Carbon\CarbonInterface yesterday(\DateTimeZone|string|int|null $timezone = null)
 *
 * @see \Hypervel\Support\DateFactory
 */
class Date extends Facade
{
    public const DEFAULT_FACADE = DateFactory::class;

    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'date';
    }

    /**
     * Resolve the facade root instance from the container.
     */
    protected static function resolveFacadeInstance(string $name): mixed
    {
        if (! isset(static::$resolvedInstance[$name]) && ! isset(static::$app, static::$app[$name])) {
            $class = static::DEFAULT_FACADE;

            static::swap(new $class);
        }

        return parent::resolveFacadeInstance($name);
    }
}
