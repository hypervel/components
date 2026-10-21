<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Client\Exceptions;

use Hypervel\Mcp\Client\OAuth\WwwAuthenticateChallenge;

class AuthorizationRequiredException extends OAuthException
{
    /**
     * Create an authorization failure with its server challenge.
     */
    public function __construct(
        string $message = 'Authorization is required to access the MCP server.',
        public ?WwwAuthenticateChallenge $challenge = null,
    ) {
        parent::__construct($message);
    }

    /**
     * Get the resource metadata URL from the challenge.
     */
    public function resourceMetadataUrl(): ?string
    {
        return $this->challenge?->resourceMetadataUrl;
    }

    /**
     * Get the requested authorization scope.
     */
    public function scope(): ?string
    {
        return $this->challenge?->scope;
    }

    /**
     * Get the authorization query parameters.
     *
     * @return array<string, string>
     */
    public function query(): array
    {
        return array_filter([
            'resource_metadata' => $this->resourceMetadataUrl(),
            'scope' => $this->scope(),
        ], static fn (?string $value): bool => $value !== null && $value !== '');
    }
}
