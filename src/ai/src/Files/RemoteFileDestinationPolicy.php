<?php

declare(strict_types=1);

namespace Hypervel\Ai\Files;

use Closure;
use Hypervel\Http\Client\Destinations\PublicDestinationPolicy;

class RemoteFileDestinationPolicy extends PublicDestinationPolicy
{
    /**
     * Create a destination policy for a remote file download.
     *
     * @param null|(Closure(string): list<string>) $resolver
     */
    public function __construct(
        protected ?Closure $resolver = null,
    ) {
        parent::__construct();
    }

    /**
     * Resolve a hostname using the configured resolver or the system resolver.
     *
     * @return list<string>
     */
    protected function resolveHost(string $host, float $timeoutSeconds): array
    {
        if ($this->resolver !== null && filter_var($host, FILTER_VALIDATE_IP) === false) {
            return ($this->resolver)($host);
        }

        return parent::resolveHost($host, $timeoutSeconds);
    }
}
