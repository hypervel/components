<?php

declare(strict_types=1);

namespace Hypervel\Passport\Bridge;

use DateInterval;
use Hypervel\Contracts\Routing\UrlGenerator;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\DeviceCodeEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Grant\DeviceCodeGrant as LeagueDeviceCodeGrant;
use League\OAuth2\Server\Repositories\DeviceCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;

class DeviceCodeGrant extends LeagueDeviceCodeGrant
{
    /**
     * Create a device code grant with request-derived verification URLs.
     */
    public function __construct(
        DeviceCodeRepositoryInterface $deviceCodeRepository,
        RefreshTokenRepositoryInterface $refreshTokenRepository,
        DateInterval $deviceCodeTTL,
        protected UrlGenerator $url,
        int $retryInterval = 5,
    ) {
        parent::__construct($deviceCodeRepository, $refreshTokenRepository, $deviceCodeTTL, '', $retryInterval);
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
