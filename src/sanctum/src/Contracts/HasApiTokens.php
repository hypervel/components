<?php

declare(strict_types=1);

namespace Hypervel\Sanctum\Contracts;

use UnitEnum;
use DateTimeInterface;
use Hypervel\Database\Eloquent\Relations\MorphMany;

interface HasApiTokens
{
    /**
     * Get the access tokens that belong to model.
     */
    public function tokens(): MorphMany;

    /**
     * Determine if the current API token has a given ability.
     */
    public function tokenCan(UnitEnum|string $ability): bool;

    /**
     * Determine if the current API token is missing a given ability.
     */
    public function tokenCant(UnitEnum|string $ability): bool;

    /**
     * Create a new personal access token for the user.
     *
     * @param array<string|UnitEnum> $abilities
     */
    public function createToken(string $name, array $abilities = ['*'], ?DateTimeInterface $expiresAt = null): \Hypervel\Sanctum\NewAccessToken;

    /**
     * Get the access token currently associated with the user.
     */
    public function currentAccessToken(): ?HasAbilities;

    /**
     * Set the current access token for the user.
     *
     * @return $this
     */
    public function withAccessToken(?HasAbilities $accessToken): static;
}
