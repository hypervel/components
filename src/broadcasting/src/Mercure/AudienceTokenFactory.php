<?php

declare(strict_types=1);

namespace Hypervel\Broadcasting\Mercure;

use Closure;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;

class AudienceTokenFactory implements TokenFactoryInterface
{
    /**
     * Create a token factory with a request-aware default audience.
     *
     * @param Closure(): string $audienceResolver
     */
    public function __construct(
        protected TokenFactoryInterface $factory,
        protected Closure $audienceResolver,
    ) {
    }

    /**
     * Create a token, preserving any audience supplied by the caller.
     */
    public function create(array $grants = [], array $additionalClaims = []): string
    {
        return $this->factory->create($grants, $additionalClaims + ['aud' => $this->getAudience()]);
    }

    /**
     * Get the current default audience for token creation and cache identity.
     */
    public function getAudience(): string
    {
        return ($this->audienceResolver)();
    }
}
