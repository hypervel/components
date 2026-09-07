<?php

declare(strict_types=1);

namespace Hypervel\Saloon\RateLimit\Traits;

use Carbon\Exceptions\InvalidFormatException;
use Hypervel\RateLimiter\AdmissionPolicy;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Response;
use Hypervel\Support\CarbonImmutable;
use UnitEnum;

trait HasRateLimits
{
    /**
     * Determine if this resource defines rate limits.
     *
     * @internal
     */
    final public function usesRateLimits(): bool
    {
        return true;
    }

    /**
     * Resolve the admission policies for an operation.
     *
     * @return list<AdmissionPolicy>
     * @internal
     */
    final public function resolveRateLimitPolicies(PendingRequest $pendingRequest): array
    {
        return $this->resolveRateLimits($pendingRequest);
    }

    /**
     * Resolve the selected rate limiter store.
     *
     * @internal
     */
    final public function resolveRateLimitStoreName(): UnitEnum|string|null
    {
        return $this->resolveRateLimitStore();
    }

    /**
     * Determine if denied rate limits should be awaited.
     *
     * @internal
     */
    final public function shouldWaitForRateLimits(): bool
    {
        return $this->waitForRateLimits();
    }

    /**
     * Resolve the stable cooldown key for an operation.
     *
     * @internal
     */
    final public function resolveRateLimitCooldownKeyFor(PendingRequest $pendingRequest): string
    {
        return $this->resolveRateLimitCooldownKey($pendingRequest);
    }

    /**
     * Resolve the cooldown imposed by a response.
     *
     * @internal
     */
    final public function resolveRateLimitCooldownFor(Response $response): ?int
    {
        return $this->resolveRateLimitCooldown($response);
    }

    /**
     * Resolve the admission policies for an operation.
     *
     * @return list<AdmissionPolicy>
     */
    abstract protected function resolveRateLimits(PendingRequest $pendingRequest): array;

    /**
     * Resolve the selected rate limiter store.
     */
    protected function resolveRateLimitStore(): UnitEnum|string|null
    {
        return null;
    }

    /**
     * Determine if denied rate limits should be awaited.
     */
    protected function waitForRateLimits(): bool
    {
        return false;
    }

    /**
     * Resolve the stable cooldown key for an operation.
     */
    protected function resolveRateLimitCooldownKey(PendingRequest $pendingRequest): string
    {
        return static::class;
    }

    /**
     * Resolve the cooldown imposed by a response.
     *
     * Override this method to support a provider-specific response format or
     * enforce a maximum duration defined by the provider.
     */
    protected function resolveRateLimitCooldown(Response $response): ?int
    {
        if ($response->status() !== 429) {
            return null;
        }

        $retryAfter = trim($response->header('Retry-After'));

        if ($retryAfter === '') {
            return null;
        }

        if (ctype_digit($retryAfter)) {
            return $this->checkedRateLimitCooldownSeconds((int) $retryAfter);
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

        $availableAt = $this->parseRateLimitRetryAfterHttpDate($format, $timestamp);

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
            $availableAt = $this->parseRateLimitRetryAfterHttpDate($format, $timestamp);
        }

        if ($availableAt === null) {
            return null;
        }

        $seconds = $availableAt->getTimestamp() - $now->getTimestamp();

        return $this->checkedRateLimitCooldownSeconds($seconds);
    }

    /**
     * Parse a validated HTTP-date shape without accepting normalized fields.
     */
    private function parseRateLimitRetryAfterHttpDate(string $format, string $timestamp): ?CarbonImmutable
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

    /**
     * Return a positive cooldown that the rate limiter can represent.
     */
    private function checkedRateLimitCooldownSeconds(int $seconds): ?int
    {
        return $seconds > 0 && $seconds <= intdiv(AdmissionPolicy::MAX_INTEGER, 1_000_000)
            ? $seconds
            : null;
    }
}
