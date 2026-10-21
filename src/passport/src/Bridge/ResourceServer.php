<?php

declare(strict_types=1);

namespace Hypervel\Passport\Bridge;

use Hypervel\Passport\ServerFactory;
use League\OAuth2\Server\AuthorizationValidators\AuthorizationValidatorInterface;
use League\OAuth2\Server\CryptKeyInterface;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\ResourceServer as LeagueResourceServer;
use Psr\Http\Message\ServerRequestInterface;

class ResourceServer extends LeagueResourceServer
{
    /**
     * Build a verifier that follows the current server settings when retained by a caller.
     */
    public function __construct(
        AccessTokenRepositoryInterface $accessTokenRepository,
        CryptKeyInterface|string $publicKey,
        ?AuthorizationValidatorInterface $authorizationValidator,
        protected ServerFactory $servers,
    ) {
        parent::__construct($accessTokenRepository, $publicKey, $authorizationValidator);
    }

    /**
     * Validate the token against the current settings without caching the result.
     */
    public function validateAuthenticatedRequest(ServerRequestInterface $request): ServerRequestInterface
    {
        $server = $this->servers->resourceServer();

        return $server instanceof self
            ? $server->validateRequest($request)
            : $server->validateAuthenticatedRequest($request);
    }

    /**
     * Validate on the selected server without resolving the settings again.
     *
     * @internal used by the factory after selecting the current server
     */
    public function validateRequest(ServerRequestInterface $request): ServerRequestInterface
    {
        return parent::validateAuthenticatedRequest($request);
    }
}
