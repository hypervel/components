<?php

declare(strict_types=1);

namespace Hypervel\Socialite\Two;

use Hypervel\Socialite\AbstractUser;
use SensitiveParameter;

class User extends AbstractUser
{
    /**
     * The user's access token.
     */
    public ?string $token = null;

    /**
     * The refresh token that can be exchanged for a new access token.
     */
    public ?string $refreshToken = null;

    /**
     * The number of seconds the access token is valid for.
     */
    public ?int $expiresIn = null;

    /**
     * The scopes the user authorized. The approved scopes may be a subset of the requested scopes.
     */
    public array $approvedScopes = [];

    /**
     * The complete access token response.
     */
    public array $accessTokenResponseBody = [];

    /**
     * Create a fake OAuth 2 user instance.
     */
    public static function fake(#[SensitiveParameter] array $attributes = []): self
    {
        $attributes = array_merge([
            'id' => '123456789',
            'nickname' => 'testuser',
            'name' => 'Test User',
            'email' => 'test@example.com',
            'avatar' => 'https://example.com/avatar.jpg',
            'token' => 'fake-token',
            'refreshToken' => 'fake-refresh-token',
            'expiresIn' => 3600,
            'approvedScopes' => [],
            'accessTokenResponseBody' => [],
        ], $attributes);

        return (new self)->setRaw($attributes)->map($attributes)
            ->setToken($attributes['token'])
            ->setRefreshToken($attributes['refreshToken'])
            ->setExpiresIn($attributes['expiresIn'])
            ->setApprovedScopes($attributes['approvedScopes'])
            ->setAccessTokenResponseBody($attributes['accessTokenResponseBody']);
    }

    /**
     * Set the token on the user.
     */
    public function setToken(#[SensitiveParameter] ?string $token): static
    {
        $this->token = $token;

        return $this;
    }

    /**
     * Set the refresh token required to obtain a new access token.
     */
    public function setRefreshToken(#[SensitiveParameter] ?string $refreshToken): static
    {
        $this->refreshToken = $refreshToken;

        return $this;
    }

    /**
     * Set the number of seconds the access token is valid for.
     */
    public function setExpiresIn(?int $expiresIn): static
    {
        $this->expiresIn = $expiresIn;

        return $this;
    }

    /**
     * Set the scopes that were approved by the user during authentication.
     */
    public function setApprovedScopes(array $approvedScopes): static
    {
        $this->approvedScopes = $approvedScopes;

        return $this;
    }

    /**
     * Set the complete access token response on the user.
     */
    public function setAccessTokenResponseBody(#[SensitiveParameter] array $accessTokenResponseBody): static
    {
        $this->accessTokenResponseBody = $accessTokenResponseBody;

        return $this;
    }
}
