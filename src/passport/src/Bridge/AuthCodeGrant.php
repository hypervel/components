<?php

declare(strict_types=1);

namespace Hypervel\Passport\Bridge;

use DateInterval;
use InvalidArgumentException;
use League\OAuth2\Server\CodeChallengeVerifiers\S256Verifier;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\AuthCodeGrant as LeagueAuthCodeGrant;
use League\OAuth2\Server\RequestAccessTokenEvent;
use League\OAuth2\Server\RequestEvent;
use League\OAuth2\Server\RequestRefreshTokenEvent;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use League\OAuth2\Server\ResponseTypes\ResponseTypeInterface;
use LogicException;
use Psr\Http\Message\ServerRequestInterface;
use stdClass;

class AuthCodeGrant extends LeagueAuthCodeGrant
{
    use AuthenticatesClients;

    /**
     * Validate the authorization request and require a hashed PKCE challenge.
     */
    public function validateAuthorizationRequest(ServerRequestInterface $request): AuthorizationRequestInterface
    {
        if ($this->getQueryStringParameter('code_challenge', $request) !== null
            && $this->getQueryStringParameter('code_challenge_method', $request) !== 'S256') {
            throw OAuthServerException::invalidRequest('code_challenge_method', 'Code challenge method must be `S256`.');
        }

        return parent::validateAuthorizationRequest($request);
    }

    /**
     * Validate ownership before code reuse can revoke previously issued credentials.
     */
    public function respondToAccessTokenRequest(
        ServerRequestInterface $request,
        ResponseTypeInterface $responseType,
        DateInterval $accessTokenTTL,
    ): ResponseTypeInterface {
        $client = $this->validateClient($request);
        $encryptedAuthCode = $this->getRequestParameter('code', $request);

        if ($encryptedAuthCode === null) {
            throw OAuthServerException::invalidRequest('code');
        }

        try {
            $authCodePayload = json_decode($this->decrypt($encryptedAuthCode));
        } catch (InvalidArgumentException) {
            throw OAuthServerException::invalidGrant('Cannot validate the provided authorization code');
        } catch (LogicException $exception) {
            throw OAuthServerException::invalidRequest('code', 'Issue decrypting the authorization code', $exception);
        }

        if (! $authCodePayload instanceof stdClass || ! is_string($authCodePayload->auth_code_id ?? null)) {
            throw OAuthServerException::invalidRequest('code', 'Authorization code malformed');
        }

        if (time() > $authCodePayload->expire_time) {
            throw OAuthServerException::invalidGrant('Authorization code has expired');
        }

        if ($authCodePayload->client_id !== $client->getIdentifier()) {
            throw OAuthServerException::invalidRequest('code', 'Authorization code was not issued to this client');
        }

        $redirectUri = $this->getRequestParameter('redirect_uri', $request);

        if ($authCodePayload->redirect_uri !== null && $redirectUri === null) {
            throw OAuthServerException::invalidRequest('redirect_uri');
        }

        if ($redirectUri !== null && $authCodePayload->redirect_uri !== $redirectUri) {
            throw OAuthServerException::invalidRequest('redirect_uri', 'Invalid redirect URI');
        }

        $this->validateCodeChallenge($authCodePayload, $this->getRequestParameter('code_verifier', $request));

        // RFC 6749 section 4.1.2 recommends revoking the products of a reused code.
        // Check ownership first so a leaked code alone cannot trigger that revocation.
        if ($this->authCodeRepository->isAuthCodeRevoked($authCodePayload->auth_code_id)) {
            throw OAuthServerException::invalidGrant('Authorization code has been revoked');
        }

        $scopes = $this->scopeRepository->finalizeScopes(
            $this->validateScopes($authCodePayload->scopes),
            $this->getIdentifier(),
            $client,
            $authCodePayload->user_id,
            $authCodePayload->auth_code_id,
        );

        $accessToken = $this->issueAccessToken($accessTokenTTL, $client, $authCodePayload->user_id, $scopes);
        $this->getEmitter()->emit(new RequestAccessTokenEvent(RequestEvent::ACCESS_TOKEN_ISSUED, $request, $accessToken));
        $responseType->setAccessToken($accessToken);

        $refreshToken = $this->issueRefreshToken($accessToken);

        if ($refreshToken !== null) {
            $this->getEmitter()->emit(new RequestRefreshTokenEvent(RequestEvent::REFRESH_TOKEN_ISSUED, $request, $refreshToken));
            $responseType->setRefreshToken($refreshToken);
        }

        return $responseType;
    }

    /**
     * Verify the hashed challenge captured during authorization.
     */
    protected function validateCodeChallenge(stdClass $authCodePayload, ?string $codeVerifier): void
    {
        if (! isset($authCodePayload->code_challenge)) {
            if ($codeVerifier !== null) {
                throw OAuthServerException::invalidRequest('code_challenge', 'code_verifier received when no code_challenge is present');
            }

            return;
        }

        if ($codeVerifier === null) {
            throw OAuthServerException::invalidRequest('code_verifier');
        }

        if (preg_match('/^[A-Za-z0-9-._~]{43,128}$/', $codeVerifier) !== 1) {
            throw OAuthServerException::invalidRequest('code_verifier', 'Code Verifier must follow the specifications of RFC-7636.');
        }

        if ($authCodePayload->code_challenge_method !== 'S256'
            || ! (new S256Verifier)->verifyCodeChallenge($codeVerifier, $authCodePayload->code_challenge)) {
            throw OAuthServerException::invalidGrant('Failed to verify `code_verifier`.');
        }
    }
}
