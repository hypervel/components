<?php

declare(strict_types=1);

namespace Hypervel\Passport\Bridge;

use DateInterval;
use Hypervel\Contracts\Routing\UrlGenerator;
use Hypervel\Passport\ClientRepository as ClientModelRepository;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\DeviceCodeEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\DeviceCodeGrant as LeagueDeviceCodeGrant;
use League\OAuth2\Server\Repositories\DeviceCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\ResponseTypes\DeviceCodeResponse;
use Override;
use Psr\Http\Message\ServerRequestInterface;

class DeviceCodeGrant extends LeagueDeviceCodeGrant
{
    use AuthenticatesClients;

    protected bool $includeVerificationUriComplete = false;

    /**
     * Create a device code grant with request-derived verification URLs.
     */
    public function __construct(
        DeviceCodeRepositoryInterface $deviceCodeRepository,
        RefreshTokenRepositoryInterface $refreshTokenRepository,
        protected DateInterval $deviceCodeTTL,
        protected UrlGenerator $url,
        int $retryInterval = 5,
    ) {
        parent::__construct($deviceCodeRepository, $refreshTokenRepository, $deviceCodeTTL, '', $retryInterval);
    }

    /**
     * Authenticate the client and apply its scope ceiling before creating a device authorization.
     */
    #[Override]
    public function respondToDeviceAuthorizationRequest(ServerRequestInterface $request): DeviceCodeResponse
    {
        // League identifies clients here without authenticating them, contrary to RFC 8628 section 3.1.
        // Apply the client's scope ceiling here while explicit scopes are still distinguishable from defaults.
        $client = $this->validateClient($request);
        $requestedScope = $this->getRequestParameter('scope', $request);
        $scopes = $this->validateScopes($requestedScope ?? $this->defaultScope);
        $model = app(ClientModelRepository::class)->findActive($client->getIdentifier());
        $allowedScopes = [];

        foreach ($scopes as $scope) {
            if (! $model->hasScope($scope->getIdentifier())) {
                if ($requestedScope === null) {
                    continue;
                }

                throw OAuthServerException::invalidScope($scope->getIdentifier());
            }

            $allowedScopes[] = $scope;
        }

        $deviceCodeEntity = $this->issueDeviceCode($this->deviceCodeTTL, $client, '', $allowedScopes);
        $response = new DeviceCodeResponse;

        if ($this->includeVerificationUriComplete) {
            $response->includeVerificationUriComplete();
        }

        if ($this->getIntervalVisibility()) {
            $response->includeInterval();
        }

        $response->setDeviceCodeEntity($deviceCodeEntity);

        return $response;
    }

    /**
     * Configure whether device responses include the complete verification URI.
     *
     * Boot-only. This grant is shared by the worker and the flag affects subsequent requests.
     */
    #[Override]
    public function setIncludeVerificationUriComplete(bool $includeVerificationUriComplete): void
    {
        parent::setIncludeVerificationUriComplete($includeVerificationUriComplete);
        $this->includeVerificationUriComplete = $includeVerificationUriComplete;
    }

    /**
     * Claim the validated device code before issuing its token.
     *
     * @param ScopeEntityInterface[] $scopes
     */
    protected function issueAccessToken(
        DateInterval $accessTokenTTL,
        ClientEntityInterface $client,
        ?string $userIdentifier,
        array $scopes = [],
    ): AccessTokenEntityInterface {
        /** @var TokenIssuance $issuance */
        $issuance = TokenIssuance::current();
        $issuance->claimGrant();

        return parent::issueAccessToken($accessTokenTTL, $client, $userIdentifier, $scopes);
    }

    /**
     * Issue a device code using the current request's verification URL.
     *
     * @param ScopeEntityInterface[] $scopes
     */
    protected function issueDeviceCode(
        DateInterval $deviceCodeTTL,
        ClientEntityInterface $client,
        string $verificationUri,
        array $scopes = [],
    ): DeviceCodeEntityInterface {
        // The grant is shared by the worker; the host and proxy prefix belong to this request.
        return parent::issueDeviceCode($deviceCodeTTL, $client, $this->url->route('passport.device'), $scopes);
    }
}
