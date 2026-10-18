<?php

declare(strict_types=1);

namespace Hypervel\Passport;

use DomainException;
use Hypervel\Contracts\Cache\LockTimeoutException;
use Hypervel\Http\Client\HttpClientException;
use Hypervel\Passport\Exceptions\UnsupportedKeyException;
use Hypervel\Support\Facades\Date;
use InvalidArgumentException;
use JsonException;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ServerRequestInterface;
use SensitiveParameter;
use UnexpectedValueException;

class ClientAuthenticator
{
    public const array METHODS = ['none', 'client_secret_basic', 'client_secret_post', 'private_key_jwt'];

    protected const int MAX_ASSERTION_BYTES = 16384;

    protected const int MAX_ASSERTION_LIFETIME = 300;

    protected const string ASSERTION_TYPE = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';

    /**
     * Create the shared OAuth client authenticator.
     */
    public function __construct(
        protected ClientRepository $clients,
        protected ClientKeyRepository $keys,
        protected ReplayCache $replays,
        protected ServerFactory $servers,
    ) {
    }

    /**
     * Authenticate the request using the client's registered method.
     */
    public function authenticate(#[SensitiveParameter] ServerRequestInterface $request): Client
    {
        $parameters = $request->getParsedBody() ?? [];

        if (! is_array($parameters)) {
            throw OAuthServerException::invalidRequest('request');
        }

        $identifier = $this->parameter($parameters, 'client_id');
        $secret = $this->parameter($parameters, 'client_secret');
        $assertion = $this->parameter($parameters, 'client_assertion');
        $assertionType = $this->parameter($parameters, 'client_assertion_type');
        $authorization = $request->getHeaderLine('Authorization');
        $method = 'none';

        if (stripos($authorization, 'Basic ') === 0) {
            if ($secret !== null || $assertion !== null || $assertionType !== null) {
                throw OAuthServerException::invalidRequest('client', 'Use only one client authentication method.');
            }

            if (($decoded = base64_decode(substr($authorization, 6), true)) === false
                || ! str_contains($decoded, ':')) {
                throw OAuthServerException::invalidClient($request);
            }

            [$basicIdentifier, $secret] = array_map(urldecode(...), explode(':', $decoded, 2));

            if ($identifier !== null && $identifier !== $basicIdentifier) {
                throw OAuthServerException::invalidClient($request);
            }

            $identifier = $basicIdentifier;
            $method = $secret === '' ? 'none' : 'client_secret_basic';
        } elseif ($assertion !== null || $assertionType !== null) {
            if ($secret !== null) {
                throw OAuthServerException::invalidRequest('client', 'Use only one client authentication method.');
            }

            if ($assertion === null || $assertionType !== self::ASSERTION_TYPE) {
                throw OAuthServerException::invalidClient($request);
            }

            [$header, $claims] = $this->assertionParts($assertion, $request);
            $subject = $claims['sub'] ?? null;

            if (! is_string($subject) || $subject === '' || ($identifier !== null && $identifier !== $subject)) {
                throw OAuthServerException::invalidClient($request);
            }

            $identifier = $subject;
            $method = 'private_key_jwt';
        } elseif ($secret !== null) {
            $method = 'client_secret_post';
        }

        if ($identifier === null || $identifier === '') {
            throw OAuthServerException::invalidRequest('client_id');
        }

        $client = $this->clients->findActive($identifier);

        if ($client === null) {
            throw OAuthServerException::invalidClient($request);
        }

        $registered = $client->getAttributes()['token_endpoint_auth_method'] ?? null;
        $confidential = $client->confidential();

        // Without a registered method, public clients use supplied credentials only for identification.
        if ($registered === null && ! $confidential && in_array($method, ['client_secret_basic', 'client_secret_post'], true)) {
            $method = 'none';
        }

        $allowed = $registered === null
            ? ($confidential ? ['client_secret_basic', 'client_secret_post'] : ['none'])
            : [$registered];

        if (! in_array($method, $allowed, true)
            || (in_array($method, ['client_secret_basic', 'client_secret_post'], true)
                && ($secret === null || $secret === '' || ! $client->secretMatches($secret)))) {
            throw OAuthServerException::invalidClient($request);
        }

        if ($method === 'private_key_jwt') {
            $this->validateAssertion($request, $client, $assertion, $header, $claims);
        }

        return $client;
    }

    /**
     * Read a form credential without changing its opaque value.
     */
    protected function parameter(#[SensitiveParameter] array $parameters, string $name): ?string
    {
        $value = $parameters[$name] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw OAuthServerException::invalidRequest($name);
        }

        return $value;
    }

    /**
     * Read bounded, untrusted assertion metadata for client and key selection.
     *
     * @return array{array<string, mixed>, array<string, mixed>}
     */
    protected function assertionParts(#[SensitiveParameter] string $assertion, ServerRequestInterface $request): array
    {
        if (strlen($assertion) > self::MAX_ASSERTION_BYTES || count($parts = explode('.', $assertion, 4)) !== 3) {
            throw OAuthServerException::invalidClient($request);
        }

        try {
            $header = json_decode(ClientJwt::urlsafeB64Decode($parts[0]), true, 32, JSON_THROW_ON_ERROR);
            $claims = json_decode(ClientJwt::urlsafeB64Decode($parts[1]), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException|InvalidArgumentException) {
            throw OAuthServerException::invalidClient($request);
        }

        if (! is_array($header) || ! is_array($claims)) {
            throw OAuthServerException::invalidClient($request);
        }

        return [$header, $claims];
    }

    /**
     * Verify the assertion before consuming its replay identifier.
     */
    protected function validateAssertion(
        ServerRequestInterface $request,
        Client $client,
        #[SensitiveParameter]
        string $assertion,
        array $header,
        array $claims,
    ): void {
        $algorithm = $client->token_endpoint_auth_signing_alg ?? 'RS256';
        $identifier = $header['kid'] ?? null;
        $issuer = $this->servers->settings()->issuer;
        $audience = $claims['aud'] ?? null;

        // Issuer-only audiences prevent audience injection; see draft-ietf-oauth-rfc7523bis, sections 4–5.
        // https://datatracker.ietf.org/doc/draft-ietf-oauth-rfc7523bis/
        if ($issuer === null || ($audience !== $issuer && $audience !== [$issuer])
            || ($header['alg'] ?? null) !== $algorithm || ! in_array($algorithm, JwkValidator::ALGORITHMS, true)
            || ($claims['iss'] ?? null) !== $client->getKey() || ($claims['sub'] ?? null) !== $client->getKey()
            || ($identifier !== null && (! is_string($identifier) || $identifier === ''))
            || ! is_string($claims['jti'] ?? null) || $claims['jti'] === '') {
            throw OAuthServerException::invalidClient($request);
        }

        $now = Date::now()->getTimestamp();
        $leeway = ClientJwt::$leeway;

        foreach (['exp', 'iat', 'nbf'] as $name) {
            if ($name !== 'exp' && ! array_key_exists($name, $claims)) {
                continue;
            }

            if ((! is_int($claims[$name] ?? null) && ! is_float($claims[$name] ?? null)) || ! is_finite($claims[$name])) {
                throw OAuthServerException::invalidClient($request);
            }
        }

        if (($claims['iat'] ?? $now) > $now + $leeway || ($claims['nbf'] ?? $now) > $now + $leeway
            || $claims['exp'] <= $now - $leeway || (isset($claims['iat']) && $claims['exp'] <= $claims['iat'])
            || $claims['exp'] - ($claims['iat'] ?? $now + $leeway) > self::MAX_ASSERTION_LIFETIME) {
            throw OAuthServerException::invalidClient($request);
        }

        try {
            $key = $this->keys->find($client, $identifier, $algorithm);

            if ($key === null) {
                throw OAuthServerException::invalidClient($request);
            }

            ClientJwt::decode($assertion, $key);
        } catch (UnsupportedKeyException $exception) {
            $error = OAuthServerException::invalidClient($request);
            $error->setPayload(['error' => 'invalid_client', 'error_description' => $exception->getMessage()]);

            throw $error;
        } catch (DomainException|InvalidArgumentException|UnexpectedValueException|HttpClientException|JsonException|LockTimeoutException) {
            throw OAuthServerException::invalidClient($request);
        }

        if (! $this->replays->consume(json_encode(['client', $issuer, $client->getKey(), $claims['jti']], JSON_THROW_ON_ERROR), (int) ceil($claims['exp'] + $leeway))) {
            throw OAuthServerException::invalidClient($request);
        }
    }
}
