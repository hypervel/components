<?php

declare(strict_types=1);

namespace Hypervel\Passport;

use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;

class ResourceRepository
{
    /**
     * Validate requested resource identifiers against the current server's catalog.
     *
     * @return list<string>
     */
    public function validate(mixed $resources): array
    {
        if (is_string($resources)) {
            $resources = [$resources];
        }

        if (! is_array($resources) || ! array_is_list($resources) || $resources === []) {
            throw $this->invalidTarget();
        }

        foreach ($resources as $resource) {
            if (! is_string($resource) || ! Passport::hasResource($resource)) {
                throw $this->invalidTarget();
            }
        }

        return array_values(array_unique($resources));
    }

    /**
     * Select audiences without widening an existing authorization.
     *
     * @param null|list<string> $requested
     * @param null|array<string, list<string>> $authorized
     * @return list<string>
     */
    public function select(?array $requested, ?array $authorized, bool $hasGrant): array
    {
        if ($requested === null && $authorized === null) {
            return [];
        }

        $selected = $this->validate($requested ?? array_keys($authorized));

        if ($hasGrant && array_diff($selected, array_keys($authorized ?? [])) !== []) {
            throw $this->invalidTarget();
        }

        return $selected;
    }

    /**
     * Limit a token to permissions approved for every selected audience.
     *
     * @param list<ScopeEntityInterface> $scopes
     * @param list<string> $audience
     * @param null|array<string, list<string>> $resources
     * @return list<ScopeEntityInterface>
     */
    public function finalizeScopes(array $scopes, array $audience, ?array $resources): array
    {
        if ($resources === null) {
            return $scopes;
        }

        // A flat scope claim applies at every audience, so a union would grant cross-resource rights.
        foreach ($audience as $resource) {
            $scopes = array_filter($scopes, static fn (ScopeEntityInterface $scope): bool => in_array($scope->getIdentifier(), $resources[$resource], true));
        }

        return array_values($scopes);
    }

    /**
     * Create the protocol error for an unacceptable resource.
     */
    protected function invalidTarget(): OAuthServerException
    {
        return new OAuthServerException('The requested resource is invalid, missing, unknown, or malformed.', 0, 'invalid_target', 400);
    }
}
