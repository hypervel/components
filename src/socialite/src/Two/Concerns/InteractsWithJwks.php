<?php

declare(strict_types=1);

namespace Hypervel\Socialite\Two\Concerns;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\SignatureInvalidException;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;
use UnexpectedValueException;

trait InteractsWithJwks
{
    /**
     * The parsed JSON Web Key Set for the current URI.
     *
     * @var null|array{url: string, keys: array, expiresAt: int}
     */
    protected ?array $jwks = null;

    /**
     * The last forced refresh attempt.
     *
     * @var null|array{url: string, attemptedAt: int}
     */
    protected ?array $jwksRefreshAttempt = null;

    /**
     * The minimum seconds between forced JWKS refreshes.
     */
    protected int $jwksRefreshCooldownSeconds = 10;

    /**
     * The fallback lifetime for JWKS responses without cache directives.
     */
    protected int $jwksDefaultTtlSeconds = 300;

    /**
     * Get the JSON Web Key Set URI for the provider.
     */
    abstract protected function getJwksUri(bool $refresh = false): string;

    /**
     * Decode a token using the provider's JSON Web Key Set.
     */
    protected function decodeUsingJwks(#[SensitiveParameter] string $token): array
    {
        try {
            return (array) JWT::decode($token, $this->getJwks());
        } catch (SignatureInvalidException) {
            return (array) JWT::decode($token, $this->getJwks(refresh: true));
        } catch (UnexpectedValueException $exception) {
            if (! str_contains($exception->getMessage(), '"kid" invalid')) {
                throw $exception;
            }

            return (array) JWT::decode($token, $this->getJwks(refresh: true));
        }
    }

    /**
     * Get the parsed JSON Web Key Set for the provider.
     */
    private function getJwks(bool $refresh = false): array
    {
        $url = $this->getJwksUri();
        $now = time();

        if (! $refresh
            && ($this->jwks['url'] ?? null) === $url
            && $now < $this->jwks['expiresAt']) {
            return $this->jwks['keys'];
        }

        if ($refresh) {
            if (($this->jwks['url'] ?? null) === $url
                && ($this->jwksRefreshAttempt['url'] ?? null) === $url
                && ($now - $this->jwksRefreshAttempt['attemptedAt']) < $this->jwksRefreshCooldownSeconds) {
                return $this->jwks['keys'];
            }

            $this->jwksRefreshAttempt = ['url' => $url, 'attemptedAt' => $now];

            $refreshedUrl = $this->getJwksUri(refresh: true);

            if ($refreshedUrl !== $url) {
                $url = $refreshedUrl;
                $this->jwksRefreshAttempt['url'] = $url;
            }
        }

        $response = $this->getHttpClient()->get($url);
        $keySet = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($keySet)) {
            throw new UnexpectedValueException('The JWKS response must be a JSON object.');
        }

        $keys = JWK::parseKeySet($keySet);
        $expiresAt = $this->getJwksExpiresAt($response, $now);

        $this->jwks = compact('url', 'keys', 'expiresAt');

        return $this->jwks['keys'];
    }

    /**
     * Get the expiration timestamp from the response cache directives.
     */
    private function getJwksExpiresAt(ResponseInterface $response, int $now): int
    {
        $maxAge = null;

        foreach ($response->getHeader('Cache-Control') as $header) {
            foreach (explode(',', $header) as $directive) {
                [$name, $value] = array_pad(explode('=', trim($directive), 2), 2, null);
                $name = strtolower(trim($name));

                if ($name === 'no-cache' || $name === 'no-store') {
                    return $now;
                }

                if ($name !== 'max-age' || $value === null) {
                    continue;
                }

                $value = trim($value);

                if (! ctype_digit($value)) {
                    continue;
                }

                $normalized = ltrim($value, '0');
                $seconds = filter_var($normalized === '' ? '0' : $normalized, FILTER_VALIDATE_INT);

                if ($seconds === false || $seconds > PHP_INT_MAX - $now) {
                    continue;
                }

                $maxAge = $maxAge === null ? $seconds : min($maxAge, $seconds);
            }
        }

        return $now + ($maxAge ?? $this->jwksDefaultTtlSeconds);
    }
}
