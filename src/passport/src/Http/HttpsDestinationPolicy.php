<?php

declare(strict_types=1);

namespace Hypervel\Passport\Http;

use Hypervel\Http\Client\Destinations\DisallowedDestinationException;
use Hypervel\Http\Client\Destinations\PublicDestinationPolicy;
use Hypervel\Http\Client\Destinations\ResolvedDestination;

class HttpsDestinationPolicy extends PublicDestinationPolicy
{
    /**
     * Resolve a public HTTPS destination, including each redirect target.
     */
    public function resolve(string $url, float $timeoutSeconds): ResolvedDestination
    {
        $this->ensureHttps($url);

        return parent::resolve($url, $timeoutSeconds);
    }

    /**
     * Validate a registered URL without making a network request.
     */
    public function validate(string $url): void
    {
        $this->ensureHttps($url);

        parent::validate($url);
    }

    /**
     * Reject plaintext endpoints before destination resolution.
     */
    protected function ensureHttps(string $url): void
    {
        if (strncasecmp($url, 'https://', 8) !== 0) {
            throw new DisallowedDestinationException('Passport remote documents require HTTPS.');
        }
    }
}
