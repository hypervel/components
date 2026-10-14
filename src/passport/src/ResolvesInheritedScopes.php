<?php

declare(strict_types=1);

namespace Hypervel\Passport;

trait ResolvesInheritedScopes
{
    /**
     * Determine if a scope exists or is inherited in the given array.
     *
     * @param string[] $haystack
     */
    protected function scopeExistsIn(string $scope, array $haystack): bool
    {
        $scopes = Passport::$withInheritedScopes
            ? $this->resolveInheritedScopes($scope)
            : [$scope];

        foreach ($scopes as $scope) {
            if (in_array($scope, $haystack, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve all possible scopes.
     *
     * @return string[]
     */
    protected function resolveInheritedScopes(string $scope): array
    {
        $parts = explode(':', $scope);

        $partsCount = count($parts);

        $scopes = [];

        for ($i = 1; $i <= $partsCount; ++$i) {
            $scopes[] = implode(':', array_slice($parts, 0, $i));
        }

        return $scopes;
    }
}
