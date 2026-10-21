<?php

declare(strict_types=1);

namespace Hypervel\Passport\Bridge;

use Hypervel\Passport\Client;
use Hypervel\Passport\ClientRepository;
use Hypervel\Passport\Passport;
use Hypervel\Support\Collection;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;

class ScopeRepository implements ScopeRepositoryInterface
{
    /**
     * Create a new scope repository.
     */
    public function __construct(
        protected ClientRepository $clients,
    ) {
    }

    /**
     * Retrieve a scope entity by its identifier.
     *
     * {@inheritdoc}
     */
    public function getScopeEntityByIdentifier(string $identifier): ?ScopeEntityInterface
    {
        return Passport::hasScope($identifier) ? new Scope($identifier) : null;
    }

    /**
     * Restrict scopes to those allowed for the grant and client.
     *
     * {@inheritdoc}
     */
    public function finalizeScopes(
        array $scopes,
        string $grantType,
        ClientEntityInterface $clientEntity,
        ?string $userIdentifier = null,
        ?string $authCodeId = null
    ): array {
        return collect($scopes)
            ->unless(
                in_array($grantType, ['personal_access', 'client_credentials'], true),
                fn (Collection $scopes): Collection => $scopes->reject(
                    fn (ScopeEntityInterface $scope): bool => $scope->getIdentifier() === '*'
                )
            )
            ->filter(fn (ScopeEntityInterface $scope): bool => Passport::hasScope($scope->getIdentifier()))
            ->when(
                $this->clients->findActive($clientEntity->getIdentifier()),
                fn (Collection $scopes, Client $client): Collection => $scopes->filter(
                    fn (ScopeEntityInterface $scope): bool => $client->hasScope($scope->getIdentifier())
                )
            )
            ->values()
            ->all();
    }
}
