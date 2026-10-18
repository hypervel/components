<?php

declare(strict_types=1);

namespace Hypervel\Passport;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\Str;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use LogicException;
use RuntimeException;

class PushedAuthorizationRequestRepository
{
    protected const string URI_PREFIX = 'urn:ietf:params:oauth:request_uri:';

    /**
     * Create the pushed authorization request repository.
     */
    public function __construct(protected Repository $config)
    {
    }

    /**
     * Store validated parameters without retaining client authentication credentials.
     *
     * @param array<string, list<string>|string> $parameters
     */
    public function store(array $parameters, AuthorizationRequestInterface $authorization): string
    {
        $uri = self::URI_PREFIX . Str::random(64);
        $redirects = $authorization->getClient()->getRedirectUri();
        $model = Passport::pushedAuthorizationRequest()->forceFill([
            'id' => hash('sha256', $uri),
            'client_id' => $authorization->getClient()->getIdentifier(),
            'parameters' => $parameters,
            'redirect_uri' => $authorization->getRedirectUri() ?? (is_array($redirects) ? $redirects[0] : $redirects),
            'scopes' => array_map(static fn (ScopeEntityInterface $scope): string => $scope->getIdentifier(), $authorization->getScopes()),
            'expires_at' => Date::now()->addSeconds($this->config->integer('passport.par.lifetime')),
        ]);

        if (! $model->save()) {
            throw new RuntimeException('Pushed authorization request creation was canceled by a model observer.');
        }

        return $uri;
    }

    /**
     * Find an unexpired reference for its client without consuming it.
     */
    public function find(string $uri, string $clientId): ?PushedAuthorizationRequest
    {
        $authorization = Passport::pushedAuthorizationRequest()->newQuery()->useWritePdo()
            ->whereKey(hash('sha256', $uri))
            ->where('expires_at', '>', Date::now())
            ->first();

        // Compare opaque input before binding it to a native UUID column.
        return $authorization?->client_id === $clientId ? $authorization : null;
    }

    /**
     * Consume an unexpired reference for its client before creating browser state.
     */
    public function consume(string $uri, string $clientId): PushedAuthorizationRequest
    {
        $model = Passport::pushedAuthorizationRequest();

        // A session can survive request failure; an outer rollback must not revive its consumed URI.
        if ($model->hasApplicableTransaction()) {
            throw new LogicException('Pushed authorization request consumption cannot run inside an application transaction.');
        }

        $authorization = $this->find($uri, $clientId);

        if ($authorization === null || $model->newQuery()->whereKey($authorization->getKey())
            ->where('client_id', $clientId)->where('expires_at', '>', Date::now())->delete() !== 1) {
            throw new OAuthServerException('The pushed authorization request is invalid or expired.', 9, 'invalid_request_uri', 400);
        }

        return $authorization;
    }
}
