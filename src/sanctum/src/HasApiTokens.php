<?php

declare(strict_types=1);

namespace Hypervel\Sanctum;

use DateTimeInterface;
use Hyperf\Database\Model\Relations\MorphMany;
use Hypervel\Sanctum\Contracts\HasAbilities;
use Hypervel\Support\Str;
use UnitEnum;

use function Hypervel\Support\enum_value;

/**
 * @template TToken of \Hypervel\Sanctum\Contracts\HasAbilities = \Hypervel\Sanctum\PersonalAccessToken
 */
trait HasApiTokens
{
    /**
     * The access token the user is using for the current request.
     *
     * @var null|TToken
     */
    protected ?HasAbilities $accessToken = null;

    /**
     * Get the access tokens that belong to model.
     *
     * @return MorphMany<TToken, $this>
     */
    public function tokens(): MorphMany
    {
        return $this->morphMany(Sanctum::$personalAccessTokenModel, 'tokenable');
    }

    /**
     * Determine if the current API token has a given ability.
     */
    public function tokenCan(UnitEnum|string $ability): bool
    {
        return $this->accessToken && $this->accessToken->can($ability);
    }

    /**
     * Determine if the current API token does not have a given ability.
     */
    public function tokenCant(UnitEnum|string $ability): bool
    {
        return ! $this->tokenCan($ability);
    }

    /**
     * Create a new personal access token for the user.
     *
     * @param array<string|UnitEnum> $abilities
     */
    public function createToken(string $name, array $abilities = ['*'], ?DateTimeInterface $expiresAt = null): NewAccessToken
    {
        $abilities = array_map(enum_value(...), $abilities);

        $plainTextToken = $this->generateTokenString();

        /** @var PersonalAccessToken $token */
        $token = $this->tokens()->create([
            'name' => $name,
            'token' => hash('sha256', $plainTextToken),
            'abilities' => $abilities,
            'expires_at' => $expiresAt,
        ]);

        return new NewAccessToken($token, $token->getKey() . '|' . $plainTextToken);
    }

    /**
     * Generate the token string.
     */
    public function generateTokenString(): string
    {
        return sprintf(
            '%s%s%s',
            config('sanctum.token_prefix', ''),
            $tokenEntropy = Str::random(40),
            hash('crc32b', $tokenEntropy)
        );
    }

    /**
     * Get the access token currently associated with the user.
     *
     * @return null|TToken
     */
    public function currentAccessToken(): ?HasAbilities
    {
        return $this->accessToken;
    }

    /**
     * Set the current access token for the user.
     *
     * @param null|TToken $accessToken
     * @return $this
     */
    public function withAccessToken(?HasAbilities $accessToken): static
    {
        $this->accessToken = $accessToken;

        return $this;
    }
}
