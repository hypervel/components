<?php

declare(strict_types=1);

namespace Hypervel\Socialite\Two;

use Firebase\JWT\JWT;
use GuzzleHttp\RequestOptions;
use Hypervel\Socialite\Two\Concerns\InteractsWithJwks;
use Hypervel\Socialite\Two\Exceptions\InvalidIssuerException;
use Hypervel\Support\Arr;
use SensitiveParameter;

class FacebookProvider extends AbstractProvider implements ProviderInterface
{
    use InteractsWithJwks;

    /**
     * The base Facebook Graph URL.
     */
    protected string $graphUrl = 'https://graph.facebook.com';

    /**
     * The Graph API version for the request.
     */
    protected string $version = 'v23.0';

    /**
     * The user fields being requested.
     */
    protected array $fields = ['name', 'email', 'gender', 'verified', 'link', 'picture.width(1920)'];

    /**
     * The scopes being requested.
     */
    protected array $scopes = ['email'];

    /**
     * Display the dialog in a popup view.
     */
    protected bool $popup = false;

    /**
     * Re-request a declined permission.
     */
    protected bool $reRequest = false;

    protected function getAuthUrl(?string $state): string
    {
        return $this->buildAuthUrlFromBase('https://www.facebook.com/' . $this->getGraphVersion() . '/dialog/oauth', $state);
    }

    protected function getTokenUrl(): string
    {
        return $this->graphUrl . '/' . $this->getGraphVersion() . '/oauth/access_token';
    }

    public function getAccessTokenResponse(#[SensitiveParameter] string $code): array
    {
        $response = $this->getHttpClient()->post($this->getTokenUrl(), [
            RequestOptions::FORM_PARAMS => $this->getTokenFields($code),
        ]);

        $data = json_decode((string) $response->getBody(), true);

        return Arr::add($data, 'expires_in', Arr::pull($data, 'expires'));
    }

    protected function getUserByToken(#[SensitiveParameter] string $token): array
    {
        $this->setLastToken($token);

        return $this->getUserByOIDCToken($token)
            ?? $this->getUserFromAccessToken($token);
    }

    /**
     * Get user based on the OIDC token.
     */
    protected function getUserByOIDCToken(#[SensitiveParameter] string $token): ?array
    {
        $segments = explode('.', $token);

        if (count($segments) !== 3) {
            return null;
        }

        $header = json_decode(JWT::urlsafeB64Decode($segments[0]), true);
        $kid = is_array($header) ? ($header['kid'] ?? null) : null;

        if ($kid === null) {
            return null;
        }

        $data = $this->decodeUsingJwks($token);

        $this->validateAudience($data['aud'] ?? null);

        if (($data['iss'] ?? null) !== 'https://www.facebook.com') {
            throw new InvalidIssuerException;
        }

        $data['id'] = $data['sub'];

        if (isset($data['given_name'])) {
            $data['first_name'] = $data['given_name'];
        }

        if (isset($data['family_name'])) {
            $data['last_name'] = $data['family_name'];
        }

        return $data;
    }

    /**
     * Get Facebook's JSON Web Key Set URI.
     */
    protected function getJwksUri(bool $refresh = false): string
    {
        return 'https://limited.facebook.com/.well-known/oauth/openid/jwks/';
    }

    /**
     * Get user based on the access token.
     */
    protected function getUserFromAccessToken(#[SensitiveParameter] string $token): array
    {
        $params = [
            'access_token' => $token,
            'fields' => implode(',', $this->getFields()),
        ];

        if (! empty($this->getClientSecret())) {
            $params['appsecret_proof'] = hash_hmac('sha256', $token, $this->getClientSecret());
        }

        $response = $this->getHttpClient()->get($this->graphUrl . '/' . $this->getGraphVersion() . '/me', [
            RequestOptions::HEADERS => [
                'Accept' => 'application/json',
            ],
            RequestOptions::QUERY => $params,
        ]);

        return json_decode((string) $response->getBody(), true);
    }

    protected function mapUserToObject(array $user): User
    {
        return (new User)->setRaw($user)->map([
            'id' => $user['id'],
            'nickname' => null,
            'name' => $user['name'] ?? null,
            'email' => $user['email'] ?? null,
            'avatar' => $avatarUrl = Arr::get($user, 'picture.data.url', $user['picture'] ?? null),
            'avatar_original' => $avatarUrl,
            'profileUrl' => $user['link'] ?? null,
        ]);
    }

    protected function getCodeFields(?string $state = null): array
    {
        $fields = parent::getCodeFields($state);

        if ($this->getPopup()) {
            $fields['display'] = 'popup';
        }

        if ($this->getReRequest()) {
            $fields['auth_type'] = 'rerequest';
        }

        return $fields;
    }

    /**
     * Set the user fields to request from Facebook.
     */
    public function fields(array $fields): static
    {
        $this->setContext('fields', $fields);

        return $this;
    }

    /**
     * Get the user fields to request from Facebook.
     */
    protected function getFields(): array
    {
        return $this->getContext('fields', $this->fields);
    }

    /**
     * Set the dialog to be displayed as a popup.
     */
    public function asPopup(): static
    {
        $this->setContext('popup', true);

        return $this;
    }

    /**
     * Determine if the dialog should be displayed as a popup.
     */
    protected function getPopup(): bool
    {
        return $this->getContext('popup', $this->popup);
    }

    /**
     * Re-request permissions which were previously declined.
     */
    public function reRequest(): static
    {
        $this->setContext('reRequest', true);

        return $this;
    }

    /**
     * Determine if permissions should be re-requested.
     */
    protected function getReRequest(): bool
    {
        return $this->getContext('reRequest', $this->reRequest);
    }

    /**
     * Get the last access token used.
     */
    public function lastToken(): ?string
    {
        return $this->getContext('lastToken');
    }

    /**
     * Set the last access token used.
     */
    protected function setLastToken(#[SensitiveParameter] string $token): static
    {
        $this->setContext('lastToken', $token);

        return $this;
    }

    /**
     * Specify which graph version should be used.
     */
    public function usingGraphVersion(string $version): static
    {
        $this->setContext('version', $version);

        return $this;
    }

    /**
     * Get the graph version being used.
     */
    protected function getGraphVersion(): string
    {
        return $this->getContext('version', $this->version);
    }
}
