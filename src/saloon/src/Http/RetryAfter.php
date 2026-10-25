<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Http;

use Carbon\Exceptions\InvalidFormatException;
use Hypervel\Support\CarbonImmutable;

class RetryAfter
{
    /**
     * Parse a Retry-After value into seconds.
     *
     * Return null when the value is missing or malformed.
     */
    public static function seconds(string $retryAfter): ?int
    {
        $retryAfter = trim($retryAfter);
        if ($retryAfter === '') {
            return null;
        }

        if (ctype_digit($retryAfter)) {
            return (int) $retryAfter;
        }

        $now = CarbonImmutable::now('GMT');
        $format = null;
        $rfc850Year = null;
        $timestamp = null;

        if (preg_match(
            '/\A(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun), (?<timestamp>\d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} GMT)\z/',
            $retryAfter,
            $matches,
        ) === 1) {
            $format = 'd M Y H:i:s \G\M\T';
            $timestamp = $matches['timestamp'];
        } elseif (preg_match(
            '/\A(?:Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday), (?<day>\d{2})-(?<month>[A-Z][a-z]{2})-(?<year>\d{2}) (?<time>\d{2}:\d{2}:\d{2}) GMT\z/',
            $retryAfter,
            $matches,
        ) === 1) {
            $year = intdiv($now->year, 100) * 100 + (int) $matches['year'];
            $format = 'd-M-Y H:i:s \G\M\T';
            $rfc850Year = $year;
            $timestamp = sprintf(
                '%s-%s-%04d %s GMT',
                $matches['day'],
                $matches['month'],
                $year,
                $matches['time'],
            );
        } elseif (preg_match(
            '/\A(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun) (?<timestamp>[A-Z][a-z]{2} (?:\d{2}| \d) \d{2}:\d{2}:\d{2} \d{4})\z/',
            $retryAfter,
            $matches,
        ) === 1) {
            $format = 'M j H:i:s Y';
            $timestamp = $matches['timestamp'];
        }

        if ($format === null || $timestamp === null) {
            return null;
        }

        $availableAt = self::parseHttpDate($format, $timestamp);

        if ($availableAt === null) {
            return null;
        }

        if ($rfc850Year !== null && $availableAt->greaterThan($now->addYears(50))) {
            $timestamp = sprintf(
                '%s-%s-%04d %s GMT',
                $matches['day'],
                $matches['month'],
                $rfc850Year - 100,
                $matches['time'],
            );
            $availableAt = self::parseHttpDate($format, $timestamp);
        }

        if ($availableAt === null) {
            return null;
        }

        return $availableAt->getTimestamp() - $now->getTimestamp();
    }

    /**
     * Parse a validated HTTP-date shape without accepting normalized fields.
     */
    private static function parseHttpDate(string $format, string $timestamp): ?CarbonImmutable
    {
        $timestamp = preg_replace(
            '/(\d{2}:\d{2}):60(?=\D|$)/',
            '$1:59',
            $timestamp,
            1,
            $leapSecond,
        );

        try {
            $availableAt = CarbonImmutable::createFromFormat($format, $timestamp, 'GMT');
        } catch (InvalidFormatException) {
            return null;
        }

        // Carbon stores errors from only its latest parse, so keep this read
        // adjacent to createFromFormat() before any other Carbon call.
        $errors = CarbonImmutable::getLastErrors();

        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return null;
        }

        return $availableAt->addSeconds($leapSecond);
    }
}
