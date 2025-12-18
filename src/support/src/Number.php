<?php

declare(strict_types=1);

namespace Hypervel\Support;

use Hyperf\Context\Context;
use Hyperf\Macroable\Macroable;
use NumberFormatter;
use RuntimeException;

class Number
{
    use Macroable;

    /**
     * The current default locale.
     */
    protected static string $locale = 'en';

    /**
     * The current default currency.
     */
    protected static string $currency = 'USD';

    /**
     * Format the given number according to the current locale.
     */
    public static function format(float|int $number, ?int $precision = null, ?int $maxPrecision = null, ?string $locale = null): false|string
    {
        static::ensureIntlExtensionIsInstalled();

        $formatter = new NumberFormatter($locale ?? static::$locale, NumberFormatter::DECIMAL);

        if (! is_null($maxPrecision)) {
            $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $maxPrecision);
        } elseif (! is_null($precision)) {
            $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, $precision);
        }

        return $formatter->format($number);
    }

    /**
     * Spell out the given number in the given locale.
     */
    public static function spell(float|int $number, ?string $locale = null, ?int $after = null, ?int $until = null): string
    {
        static::ensureIntlExtensionIsInstalled();

        if (! is_null($after) && $number <= $after) {
            return static::format($number, locale: $locale);
        }

        if (! is_null($until) && $number >= $until) {
            return static::format($number, locale: $locale);
        }

        $formatter = new NumberFormatter($locale ?? static::$locale, NumberFormatter::SPELLOUT);

        return $formatter->format($number);
    }

    /**
     * Convert the given number to ordinal form.
     */
    public static function ordinal(float|int $number, ?string $locale = null): string
    {
        static::ensureIntlExtensionIsInstalled();

        $formatter = new NumberFormatter($locale ?? static::$locale, NumberFormatter::ORDINAL);

        return $formatter->format($number);
    }

    /**
     * Convert the given number to its percentage equivalent.
     */
    public static function percentage(float|int $number, int $precision = 0, ?int $maxPrecision = null, ?string $locale = null): false|string
    {
        static::ensureIntlExtensionIsInstalled();

        $formatter = new NumberFormatter($locale ?? static::$locale, NumberFormatter::PERCENT);

        if (! is_null($maxPrecision)) {
            $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $maxPrecision);
        } else {
            $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, $precision);
        }

        return $formatter->format($number / 100);
    }

    /**
     * Convert the given number to its currency equivalent.
     */
    public static function currency(float|int $number, string $in = '', ?string $locale = null): false|string
    {
        static::ensureIntlExtensionIsInstalled();

        $formatter = new NumberFormatter($locale ?? static::$locale, NumberFormatter::CURRENCY);

        return $formatter->formatCurrency($number, ! empty($in) ? $in : static::$currency);
    }

    /**
     * Convert the given number to its file size equivalent.
     */
    public static function fileSize(float|int $bytes, int $precision = 0, ?int $maxPrecision = null): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];

        for ($i = 0; ($bytes / 1024) > 0.9 && ($i < count($units) - 1); ++$i) {
            $bytes /= 1024;
        }

        return sprintf('%s %s', static::format($bytes, $precision, $maxPrecision), $units[$i]);
    }

    /**
     * Convert the number to its human-readable equivalent.
     */
    public static function abbreviate(float|int $number, int $precision = 0, ?int $maxPrecision = null): bool|string
    {
        return static::forHumans($number, $precision, $maxPrecision, abbreviate: true);
    }

    /**
     * Convert the number to its human-readable equivalent.
     */
    public static function forHumans(float|int $number, int $precision = 0, ?int $maxPrecision = null, bool $abbreviate = false): false|string
    {
        return static::summarize($number, $precision, $maxPrecision, $abbreviate ? [
            3 => 'K',
            6 => 'M',
            9 => 'B',
            12 => 'T',
            15 => 'Q',
        ] : [
            3 => ' thousand',
            6 => ' million',
            9 => ' billion',
            12 => ' trillion',
            15 => ' quadrillion',
        ]);
    }

    /**
     * Convert the number to its human-readable equivalent.
     */
    protected static function summarize(float|int $number, int $precision = 0, ?int $maxPrecision = null, array $units = []): false|string
    {
        if (empty($units)) {
            $units = [
                3 => 'K',
                6 => 'M',
                9 => 'B',
                12 => 'T',
                15 => 'Q',
            ];
        }

        switch (true) {
            case floatval($number) === 0.0:
                return $precision > 0 ? static::format(0, $precision, $maxPrecision) : '0';
            case $number < 0:
                return sprintf('-%s', static::summarize(abs($number), $precision, $maxPrecision, $units));
            case $number >= 1e15:
                return sprintf('%s' . end($units), static::summarize($number / 1e15, $precision, $maxPrecision, $units));
        }

        $numberExponent = floor(log10($number));
        $displayExponent = $numberExponent - ($numberExponent % 3);
        $number /= pow(10, $displayExponent);

        return trim(sprintf('%s%s', static::format($number, $precision, $maxPrecision), $units[(string) $displayExponent] ?? ''));
    }

    /**
     * Clamp the given number between the given minimum and maximum.
     */
    public static function clamp(float|int $number, float|int $min, float|int $max): float|int
    {
        return min(max($number, $min), $max);
    }

    /**
     * Split the given number into pairs of min/max values.
     */
    public static function pairs(float|int $to, float|int $by, float|int $offset = 1): array
    {
        $output = [];

        for ($lower = 0; $lower < $to; $lower += $by) {
            $upper = $lower + $by;

            if ($upper > $to) {
                $upper = $to;
            }

            $output[] = [$lower + $offset, $upper];
        }

        return $output;
    }

    /**
     * Remove any trailing zero digits after the decimal point of the given number.
     */
    public static function trim(float|int $number): float|int
    {
        return json_decode(json_encode($number));
    }

    /**
     * Execute the given callback using the given locale.
     */
    public static function withLocale(string $locale, callable $callback): mixed
    {
        $previousLocale = static::$locale;

        static::useLocale($locale);

        return tap($callback(), fn () => static::useLocale($previousLocale));
    }

    /**
     * Execute the given callback using the given currency.
     */
    public static function withCurrency(string $currency, callable $callback): mixed
    {
        $previousCurrency = static::$currency;

        static::useCurrency($currency);

        return tap($callback(), fn () => static::useCurrency($previousCurrency));
    }

    /**
     * Set the default locale.
     */
    public static function useLocale(string $locale): void
    {
        Context::set('__support.number.locale', $locale);
    }

    /**
     * Set the default currency.
     */
    public static function useCurrency(string $currency): void
    {
        Context::get('__support.number.currency', $currency);
    }

    /**
     * Get the default locale.
     */
    public static function defaultLocale(): string
    {
        return Context::get('__support.number.locale', static::$locale);
    }

    /**
     * Get the default currency.
     */
    public static function defaultCurrency(): string
    {
        return Context::get('__support.number.currency', static::$currency);
    }

    /**
     * Ensure the "intl" PHP extension is installed.
     */
    protected static function ensureIntlExtensionIsInstalled(): void
    {
        if (! extension_loaded('intl')) {
            $method = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'];

            throw new RuntimeException('The "intl" PHP extension is required to use the [' . $method . '] method.');
        }
    }
}
