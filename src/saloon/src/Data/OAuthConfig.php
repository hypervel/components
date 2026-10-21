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
     * @param list<?string> $defaultScopes
     * @param null|callable(Request): void $requestModifier
     */
    public function __construct(
        public string $clientId,
        #[SensitiveParameter]
        public string $clientSecret,
        public string $redirectUri = '',
        public string $authorizeEndpoint = 'authorize',
        public string $tokenEndpoint = 'token',
        public ?string $refreshEndpoint = null,
        public string $userEndpoint = 'user',
        public array $defaultScopes = [],
        ?callable $requestModifier = null,
        public bool $allowBaseUrlOverride = false,
    ) {
        $this->requestModifier = $requestModifier === null ? null : $requestModifier(...);
    }

    /**
     * Merge the default scopes with the given scopes, ignoring null and empty scopes.
     *
     * @param list<?string> $scopes
     * @return array<int, string>
     */
    public function scopes(array $scopes = []): array
    {
        return array_filter(
            [...$this->defaultScopes, ...$scopes],
            static fn (?string $scope): bool => $scope !== null && $scope !== '',
        );
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
     *
     * @throws OAuthConfigValidationException
     */
    public function validate(bool $withRedirectUrl = true): bool
    {
        if ($this->clientId === '') {
            throw new OAuthConfigValidationException('The Client ID is empty or has not been provided.');
        }

        if ($this->clientSecret === '') {
            throw new OAuthConfigValidationException('The Client Secret is empty or has not been provided.');
        }

        if ($withRedirectUrl && $this->redirectUri === '') {
            throw new OAuthConfigValidationException('The Redirect URI is empty or has not been provided.');
        }

        return true;
    }
}
