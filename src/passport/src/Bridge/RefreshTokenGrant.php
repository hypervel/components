<?php

declare(strict_types=1);

namespace Hypervel\Passport\Bridge;

use League\OAuth2\Server\Grant\RefreshTokenGrant as LeagueRefreshTokenGrant;
use Psr\Http\Message\ServerRequestInterface;
use UnexpectedValueException;

class RefreshTokenGrant extends LeagueRefreshTokenGrant
{
    use AuthenticatesClients;

    /**
     * Reject another grant's encrypted credential before League reads refresh fields.
     */
    protected function decrypt(string $encryptedData): string
    {
        $plaintext = parent::decrypt($encryptedData);
        $payload = json_decode($plaintext, true);

        // Codes share the encryption key but are not refresh tokens. League otherwise raises a PHP error.
        // @TODO: Remove when our minimum League version fixes https://github.com/thephpleague/oauth2-server/issues/1501.
        if (! is_string($payload['refresh_token_id'] ?? null) || $payload['refresh_token_id'] === '') {
            throw new UnexpectedValueException('The credential is not a refresh token.');
        }

        return $plaintext;
    }

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
