<?php

declare(strict_types=1);

namespace Hypervel\Passport\Bridge;

use Defuse\Crypto\Key;
use Hypervel\Passport\ServerFactory;
use League\OAuth2\Server\AuthorizationServer as LeagueAuthorizationServer;
use League\OAuth2\Server\CryptKeyInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use League\OAuth2\Server\ResponseTypes\ResponseTypeInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SensitiveParameter;

class AuthorizationServer extends LeagueAuthorizationServer
{
    /**
     * Build a configured server whose request entry points follow current settings.
     */
    public function __construct(
        ClientRepositoryInterface $clientRepository,
        AccessTokenRepositoryInterface $accessTokenRepository,
        ScopeRepositoryInterface $scopeRepository,
        #[SensitiveParameter]
        CryptKeyInterface|string $privateKey,
        #[SensitiveParameter]
        Key|string $encryptionKey,
        ?ResponseTypeInterface $responseType,
        protected ServerFactory $servers,
        protected bool $personalAccess = false,
    ) {
        parent::__construct($clientRepository, $accessTokenRepository, $scopeRepository, $privateKey, $encryptionKey, $responseType);
    }

    /**
     * Validate authorization using the current server settings.
     */
    public function validateAuthorizationRequest(ServerRequestInterface $request): AuthorizationRequestInterface
    {
        return $this->currentServer()->validateAuthorization($request);
    }

    /**
     * Validate authorization on the selected server without selecting it again.
     */
    protected function validateAuthorization(ServerRequestInterface $request): AuthorizationRequestInterface
    {
        try {
            return parent::validateAuthorizationRequest($request);
        } catch (OAuthServerException $exception) {
            if ($exception->getErrorType() !== 'unsupported_grant_type') {
                throw $exception;
            }

            // @TODO Remove when League fixes authorization dispatch: https://github.com/thephpleague/oauth2-server/issues/1216
            if (! isset($request->getQueryParams()['client_id'])) {
                throw OAuthServerException::invalidRequest('client_id');
            }

            throw new OAuthServerException(
                'The authorization server does not support the requested response type.',
                2,
                'unsupported_response_type',
                400,
            );
        }
    }

    /**
     * Complete authorization within the consent controller's issuance context.
     */
    public function completeAuthorizationRequest(AuthorizationRequestInterface $authRequest, ResponseInterface $response): ResponseInterface
    {
        $complete = fn (): ResponseInterface => $this->currentServer()->completeAuthorization($authRequest, $response);

        return TokenIssuance::current() === null
            ? TokenIssuance::run($this->servers, $complete)
            : $complete();
    }

    /**
     * Complete authorization on the selected server.
     */
    protected function completeAuthorization(AuthorizationRequestInterface $authRequest, ResponseInterface $response): ResponseInterface
    {
        return parent::completeAuthorizationRequest($authRequest, $response);
    }

    /**
     * Issue a device code using freshly selected settings.
     */
    public function respondToDeviceAuthorizationRequest(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return TokenIssuance::run($this->servers, fn (): ResponseInterface => $this->currentServer()->issueDeviceAuthorization($request, $response));
    }

    /**
     * Issue a device authorization response on the selected server.
     */
    protected function issueDeviceAuthorization(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return parent::respondToDeviceAuthorizationRequest($request, $response);
    }

    /**
     * Complete device authorization within its consent context.
     */
    public function completeDeviceAuthorizationRequest(string $deviceCode, string $userId, bool $userApproved): void
    {
        $complete = fn () => $this->currentServer()->completeDeviceAuthorization($deviceCode, $userId, $userApproved);

        if (TokenIssuance::current() === null) {
            TokenIssuance::run($this->servers, $complete);
        } else {
            $complete();
        }
    }

    /**
     * Complete device authorization on the selected server.
     */
    protected function completeDeviceAuthorization(string $deviceCode, string $userId, bool $userApproved): void
    {
        parent::completeDeviceAuthorizationRequest($deviceCode, $userId, $userApproved);
    }

    /**
     * Build and sign a token response before committing its issuance.
     */
    public function respondToAccessTokenRequest(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return TokenIssuance::run(
            $this->servers,
            fn (): ResponseInterface => $this->currentServer()->issueAccessTokenResponse($request, $response),
            tokenRequest: true,
        );
    }

    /**
     * Generate the token response on the server selected for the pinned key.
     */
    protected function issueAccessTokenResponse(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return parent::respondToAccessTokenRequest($request, $response);
    }

    /**
     * Get the configured server for the current operation.
     */
    protected function currentServer(): self
    {
        return $this->personalAccess ? $this->servers->personalAccessServer() : $this->servers->authorizationServer();
    }
}
