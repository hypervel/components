<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\SocialiteProperties;

use Hypervel\Http\Request;
use Hypervel\Socialite\Two\AbstractProvider;
use Hypervel\Socialite\Two\FacebookProvider;
use Hypervel\Socialite\Two\GithubProvider;
use Hypervel\Socialite\Two\User;

class CustomProvider extends AbstractProvider
{
    protected array $scopes = ['profile'];

    public function __construct(Request $request, string $clientId, string $clientSecret, string $redirectUrl)
    {
        parent::__construct($request, $clientId, $clientSecret, $redirectUrl);

        $this->usesPKCE = true;
    }

    public function withSecret(string $secret): static
    {
        $this->clientSecret = $secret;

        return $this;
    }

    public function getScopes(): array
    {
        return [...$this->scopes, 'openid'];
    }

    protected function getAuthUrl(?string $state): string
    {
        return $this->buildAuthUrlFromBase('https://example.com/auth?scope=' . implode($this->scopeSeparator, $this->scopes), $state);
    }

    protected function getTokenUrl(): string
    {
        return 'https://example.com/token?' . http_build_query($this->parameters);
    }

    protected function getUserByToken(string $token): array
    {
        return [];
    }

    protected function mapUserToObject(array $user): User
    {
        return (new User)->setRaw($user);
    }
}

class CustomFacebookProvider extends FacebookProvider
{
    public function opensInPopup(): bool
    {
        return $this->popup;
    }
}

class FieldsGithubProvider extends GithubProvider
{
    protected array $fields = ['login'];

    public function requestedFields(): array
    {
        return $this->fields;
    }
}
