<?php

declare(strict_types=1);

namespace Hypervel\Socialite\Two;

use Exception;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use GuzzleHttp\RequestOptions;
use Hypervel\Support\Arr;
use phpseclib3\Crypt\RSA;
use phpseclib3\Math\BigInteger;

class FacebookProvider extends AbstractProvider implements ProviderInterface
{
    /**
     * The base Facebook Graph URL.
     */
    protected string $graphUrl = 'https://graph.facebook.com';

    /**
     * The Graph API version for the request.
     */
    protected string $version = 'v3.3';

    /**
     * The user fields being requested.
     */
    protected array $fields = ['name', 'email', 'gender', 'verified', 'link'];

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

    /**
     * The access token that was last used to retrieve a user.
     */
    protected ?string $lastToken = null;

    protected function getAuthUrl(string $state): string
    {
        return $this->buildAuthUrlFromBase('https://www.facebook.com/' . $this->version . '/dialog/oauth', $state);
    }

    protected function getTokenUrl(): string
    {
        return $this->graphUrl . '/' . $this->version . '/oauth/access_token';
    }

    public function getAccessTokenResponse(string $code): array
    {
        $response = $this->getHttpClient()->post($this->getTokenUrl(), [
            RequestOptions::FORM_PARAMS => $this->getTokenFields($code),
        ]);

        $data = json_decode((string) $response->getBody(), true);

        return Arr::add($data, 'expires_in', Arr::pull($data, 'expires'));
    }

    protected function getUserByToken(string $token): array
    {
        $this->lastToken = $token;

        return $this->getUserByOIDCToken($token)
            ?? $this->getUserFromAccessToken($token);
    }

    /**
     * Get user based on the OIDC token.
     */
    protected function getUserByOIDCToken(string $token): ?array
    {
        $kid = json_decode(base64_decode(explode('.', $token)[0]), true)['kid'] ?? null;

        if ($kid === null) {
            return null;
        }

        $data = (array) JWT::decode($token, $this->getPublicKeyOfOIDCToken($kid));

        throw_if($data['aud'] !== $this->clientId, new Exception('Token has incorrect audience.'));
        throw_if($data['iss'] !== 'https://www.facebook.com', new Exception('Token has incorrect issuer.'));

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
     * Get the public key to verify the signature of OIDC token.
     */
    protected function getPublicKeyOfOIDCToken(string $kid): Key
    {
        $response = $this->getHttpClient()->get('https://limited.facebook.com/.well-known/oauth/openid/jwks/');

        $key = Arr::first(json_decode($response->getBody()->getContents(), true)['keys'], function ($key) use ($kid) {
            return $key['kid'] === $kid;
        });

        $key['n'] = new BigInteger(JWT::urlsafeB64Decode($key['n']), 256);
        $key['e'] = new BigInteger(JWT::urlsafeB64Decode($key['e']), 256);

        // @phpstan-ignore-next-line
        return new Key((string) RSA::load($key), 'RS256');
    }

    /**
     * Get user based on the access token.
     */
    protected function getUserFromAccessToken(string $token): array
    {
        $params = [
            'access_token' => $token,
            'fields' => implode(',', $this->fields),
        ];

        if (! empty($this->clientSecret)) {
            $params['appsecret_proof'] = hash_hmac('sha256', $token, $this->clientSecret);
        }

        $response = $this->getHttpClient()->get($this->graphUrl . '/' . $this->version . '/me', [
            RequestOptions::HEADERS => [
                'Accept' => 'application/json',
            ],
            RequestOptions::QUERY => $params,
        ]);

        return json_decode((string) $response->getBody(), true);
    }

    protected function mapUserToObject(array $user): User
    {
        if (! isset($user['sub'])) {
            $avatarUrl = $this->graphUrl . '/' . $this->version . '/' . $user['id'] . '/picture';

            $avatarOriginalUrl = $avatarUrl . '?width=1920';
        }

        return (new User())->setRaw($user)->map([
            'id' => $user['id'],
            'nickname' => null,
            'name' => $user['name'] ?? null,
            'email' => $user['email'] ?? null,
            'avatar' => $avatarUrl ?? $user['picture'] ?? null,
            'avatar_original' => $avatarOriginalUrl ?? $user['picture'] ?? null,
            'profileUrl' => $user['link'] ?? null,
        ]);
    }

    protected function getCodeFields(?string $state = null): array
    {
        $fields = parent::getCodeFields($state);

        if ($this->popup) {
            $fields['display'] = 'popup';
        }

        if ($this->reRequest) {
            $fields['auth_type'] = 'rerequest';
        }

        return $fields;
    }

    /**
     * Set the user fields to request from Facebook.
     */
    public function fields(array $fields): static
    {
        $this->fields = $fields;

        return $this;
    }

    /**
     * Set the dialog to be displayed as a popup.
     */
    public function asPopup(): static
    {
        $this->popup = true;

        return $this;
    }

    /**
     * Re-request permissions which were previously declined.
     */
    public function reRequest(): static
    {
        $this->reRequest = true;

        return $this;
    }

    /**
     * Get the last access token used.
     */
    public function lastToken(): ?string
    {
        return $this->lastToken;
    }

    /**
     * Specify which graph version should be used.
     */
    public function usingGraphVersion(string $version): static
    {
        $this->version = $version;

        return $this;
    }
}
