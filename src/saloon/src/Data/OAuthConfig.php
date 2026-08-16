<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Data;

use Closure;
use Hypervel\Saloon\Exceptions\OAuthConfigValidationException;
use Hypervel\Saloon\Http\Request;
use SensitiveParameter;

final readonly class OAuthConfig
{
    /**
     * The request modifier.
     *
     * @var null|Closure(Request): void
     */
    public ?Closure $requestModifier;

    /**
     * Create an OAuth 2 configuration.
     *
     * @param list<string> $defaultScopes
     * @param null|callable(Request): void $requestModifier
     */
    public function __construct(
        public string $clientId,
        #[SensitiveParameter]
        public string $clientSecret,
        public string $redirectUri = '',
        public string $authorizeEndpoint = 'authorize',
        public string $tokenEndpoint = 'token',
        public string $userEndpoint = 'user',
        public array $defaultScopes = [],
        ?callable $requestModifier = null,
        public bool $allowBaseUrlOverride = false,
    ) {
        $this->requestModifier = $requestModifier === null ? null : $requestModifier(...);
    }

    /**
     * Apply the configured request modifier.
     *
     * @template TRequest of Request
     * @param TRequest $request
     * @return TRequest
     */
    public function modify(Request $request): Request
    {
        $this->requestModifier?->__invoke($request);

        return $request;
    }

    /**
     * Validate the OAuth 2 configuration.
     */
    public function validate(bool $withRedirectUri = true): void
    {
        if ($this->clientId === '') {
            throw new OAuthConfigValidationException('The client ID is empty or has not been provided.');
        }

        if ($this->clientSecret === '') {
            throw new OAuthConfigValidationException('The client secret is empty or has not been provided.');
        }

        if ($withRedirectUri && $this->redirectUri === '') {
            throw new OAuthConfigValidationException('The redirect URI is empty or has not been provided.');
        }
    }
}
