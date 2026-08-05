<?php

declare(strict_types=1);

namespace Hypervel\RateLimiter;

use Closure;
use Hypervel\RateLimiter\Exceptions\InvalidRateLimitException;

class KeyResolver
{
    protected int $hashSeed;

    /**
     * Create a new physical key resolver.
     *
     * @param null|Closure(string): ?string $scopeResolver
     */
    public function __construct(
        protected string $prefix,
        protected ?Closure $scopeResolver = null,
    ) {
        if ($prefix === '') {
            throw new InvalidRateLimitException('The rate limiter prefix may not be empty.');
        }

        $this->hashSeed = (int) hexdec(substr(
            hash('xxh128', 'rate-limiter|' . $prefix),
            0,
            15,
        ));
    }

    /**
     * Resolve a stable fixed-length physical key.
     */
    public function resolve(AdmissionPolicy|Backoff $policy, ?string $limiterName = null): string
    {
        $identity = $this->segment('domain', 'hypervel-rate-limiter-v1')
            . $this->segment('prefix', $this->prefix);

        if ($limiterName !== null) {
            $identity .= $this->segment('limiter', $limiterName);

            if (! ($policy instanceof AdmissionPolicy && $policy->global)) {
                $scope = $this->scopeResolver?->__invoke($limiterName);

                if ($scope !== null) {
                    $identity .= $this->segment('scope', $scope);
                }
            }
        }

        $identity .= $this->segment('key', $policy->key)
            . $this->policyIdentity($policy);

        return hash('xxh128', $identity, false, ['seed' => $this->hashSeed]);
    }

    /**
     * Build the stable policy portion of an identity.
     */
    protected function policyIdentity(AdmissionPolicy|Backoff $policy): string
    {
        // Laravel's fallbackKey() changes only colliding named keys. Hypervel
        // always includes stable policy parameters so configuration changes
        // start independent state on every store.
        return match (true) {
            $policy instanceof Limit => $this->segment('policy', 'fixed-window')
                . $this->segment('max-attempts', (string) $policy->maxAttempts)
                . $this->segment('decay-seconds', (string) $policy->decaySeconds)
                . $this->segment('global', $policy->global ? '1' : '0'),
            $policy instanceof LeakyBucket => $this->segment('policy', 'leaky-bucket')
                . $this->segment('rate', (string) $policy->rate)
                . $this->segment('period-microseconds', (string) $policy->periodMicroseconds)
                . $this->segment('burst', (string) $policy->burst)
                . $this->segment('global', $policy->global ? '1' : '0'),
            $policy instanceof Backoff => $this->segment('policy', 'exponential-backoff')
                . $this->segment('after', (string) $policy->after)
                . $this->segment('initial-delay', (string) $policy->initialDelay)
                . $this->segment('max-delay', (string) $policy->maxDelay)
                . $this->segment('reset-after', (string) $policy->resetAfter),
            default => throw new InvalidRateLimitException(sprintf(
                'Policy [%s] is not supported.',
                $policy::class,
            )),
        };
    }

    /**
     * Encode a domain-tagged length-prefixed identity segment.
     */
    protected function segment(string $domain, string $value): string
    {
        return strlen($domain) . ':' . $domain . strlen($value) . ':' . $value;
    }
}
