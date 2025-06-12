<?php

declare(strict_types=1);

namespace Hypervel\Socialite\One;

use Hypervel\Http\Contracts\RequestContract;
use Hypervel\Http\Contracts\ResponseContract;
use Hypervel\Socialite\Contracts\Provider as ProviderContract;
use Hypervel\Socialite\HasProviderContext;
use League\OAuth1\Client\Credentials\TokenCredentials;
use League\OAuth1\Client\Server\Server;
use Psr\Http\Message\ResponseInterface;

abstract class AbstractProvider implements ProviderContract
{
    use HasProviderContext;

    /**
     * Create a new provider instance.
     *
     * @param RequestContract $request the HTTP request instance
     * @param ResponseContract $response the HTTP response instance
     * @param Server $server the OAuth server implementation
     */
    public function __construct(
        protected RequestContract $request,
        protected ResponseContract $response,
        protected Server $server
    ) {
    }

    /**
     * Redirect the user to the authentication page for the provider.
     */
    public function redirect(): ResponseInterface
    {
        $this->request->session()->put(
            'oauth.temp',
            $temp = $this->server->getTemporaryCredentials()
        );

        return $this->response->redirect(
            $this->server->getAuthorizationUrl($temp)
        );
    }

    /**
     * Get the User instance for the authenticated user.
     *
     * @throws MissingVerifierException
     */
    public function user(): User
    {
        if (! $this->hasNecessaryVerifier()) {
            throw new MissingVerifierException('Invalid request. Missing OAuth verifier.');
        }

        $token = $this->getToken();

        $user = $this->server->getUserDetails(
            $token,
            $this->shouldBypassCache($token->getIdentifier(), $token->getSecret())
        );

        $instance = (new User())->setRaw($user->extra)
            ->setToken($token->getIdentifier(), $token->getSecret());

        return $instance->map([
            'id' => $user->uid,
            'nickname' => $user->nickname,
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => $user->imageUrl,
        ]);
    }

    /**
     * Get a Social User instance from a known access token and secret.
     */
    public function userFromTokenAndSecret(string $token, string $secret): User
    {
        $tokenCredentials = new TokenCredentials();

        $tokenCredentials->setIdentifier($token);
        $tokenCredentials->setSecret($secret);

        $user = $this->server->getUserDetails(
            $tokenCredentials,
            $this->shouldBypassCache($token, $secret)
        );

        $instance = (new User())->setRaw($user->extra)
            ->setToken($tokenCredentials->getIdentifier(), $tokenCredentials->getSecret());

        return $instance->map([
            'id' => $user->uid,
            'nickname' => $user->nickname,
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => $user->imageUrl,
        ]);
    }

    /**
     * Get the token credentials for the request.
     */
    protected function getToken(): TokenCredentials
    {
        $temp = $this->request->session()->get('oauth.temp');

        if (! $temp) {
            throw new MissingTemporaryCredentialsException('Missing temporary OAuth credentials.');
        }

        return $this->server->getTokenCredentials(
            $temp,
            $this->request->input('oauth_token'),
            $this->request->input('oauth_verifier')
        );
    }

    /**
     * Determine if the request has the necessary OAuth verifier.
     */
    protected function hasNecessaryVerifier(): bool
    {
        return $this->request->has(['oauth_token', 'oauth_verifier']);
    }

    /**
     * Determine if the user information cache should be bypassed.
     */
    protected function shouldBypassCache(string $token, string $secret): bool
    {
        $newHash = sha1($token . '_' . $secret);

        if (! empty($this->userHash) && $newHash !== $this->getUserHash()) {
            $this->setUserHash($newHash);

            return true;
        }

        $this->setUserHash($this->getUserHash() ?: $newHash);

        return false;
    }

    /**
     * Get the hash representing the last requested user.
     */
    protected function getUserHash(): ?string
    {
        return $this->getContext('userHash');
    }

    /**
     * Set the hash representing the last requested user.
     */
    protected function setUserHash(string $hash): static
    {
        $this->setContext('userHash', $hash);

        return $this;
    }

    /**
     * Set the request instance.
     */
    public function setRequest(RequestContract $request): static
    {
        $this->request = $request;

        return $this;
    }
}
