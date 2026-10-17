<?php

declare(strict_types=1);

namespace Hypervel\Passport\Bridge;

use League\OAuth2\Server\Grant\RefreshTokenGrant as LeagueRefreshTokenGrant;
use Psr\Http\Message\ServerRequestInterface;

class RefreshTokenGrant extends LeagueRefreshTokenGrant
{
    use AuthenticatesClients;

    /**
     * Preserve refresh authorization independently of the requested access-token scopes.
     *
     * @return array<string, mixed>
     */
    protected function validateOldRefreshToken(ServerRequestInterface $request, string $clientId): array
    {
        $payload = parent::validateOldRefreshToken($request, $clientId);

        if ($issuance = TokenIssuance::current()) {
            // RFC 6749 section 6 keeps replacement refresh scopes identical, even for narrower access tokens.
            // League currently derives them from the access token: https://github.com/thephpleague/oauth2-server/issues/1117
            $issuance->refreshTokenScopes = $payload['scopes'];
        }

        return $payload;
    }
}
