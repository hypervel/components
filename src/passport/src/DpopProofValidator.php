<?php

declare(strict_types=1);

namespace Hypervel\Passport;

use DomainException;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Passport\Exceptions\UnsupportedKeyException;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\Uri;
use InvalidArgumentException;
use JsonException;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ServerRequestInterface;
use SensitiveParameter;
use UnexpectedValueException;

class DpopProofValidator
{
    protected const int MAX_PROOF_BYTES = 16384;

    protected const int PROOF_LIFETIME = 60;

    protected const string CONTEXT_KEY = '__passport.dpop';

    /**
     * Create the proof validator with shared replay storage and server policy.
     */
    public function __construct(
        protected Repository $config,
        protected JwkValidator $keys,
        protected ReplayCache $replays,
        protected ServerFactory $servers,
    ) {
    }

    /**
     * Validate a proof and return its public key thumbprint.
     */
    public function validate(
        #[SensitiveParameter]
        ServerRequestInterface $request,
        #[SensitiveParameter]
        ?string $accessToken = null,
        ?string $thumbprint = null,
    ): string {
        if (! $this->config->boolean('passport.dpop.enabled')) {
            throw $this->invalidProof('DPoP is not enabled on this server.');
        }

        $proofs = $request->getHeader('DPoP');

        if (count($proofs) !== 1 || $proofs[0] === '' || strlen($proofs[0]) > self::MAX_PROOF_BYTES) {
            throw $this->invalidProof('Supply exactly one DPoP proof.');
        }

        $proof = $proofs[0];
        $method = $request->getMethod();
        $uri = $this->normalizeUri((string) $request->getUri());
        $replayProtection = $this->servers->settings()->dpopReplayProtection
            ?? $this->config->boolean('passport.dpop.replay_protection');
        $inputs = [$proof, $method, $uri, $accessToken, $thumbprint, $replayProtection];
        $cached = RequestContext::has() ? RequestContext::getValue(self::CONTEXT_KEY) : null;

        // Guards and scope middleware may validate the same request more than once.
        if ($cached !== null && $cached['inputs'] === $inputs) {
            return $cached['thumbprint'];
        }

        try {
            $parts = explode('.', $proof, 4);

            if (count($parts) !== 3) {
                throw $this->invalidProof();
            }

            $header = json_decode(ClientJwt::urlsafeB64Decode($parts[0]), true, 32, JSON_THROW_ON_ERROR);

            if (! is_array($header) || ($header['typ'] ?? null) !== 'dpop+jwt'
                || ! is_string($header['alg'] ?? null) || ! is_array($header['jwk'] ?? null)) {
                throw $this->invalidProof();
            }

            $key = $this->keys->validate($header['jwk'], $header['alg']);
            $claims = ClientJwt::decode($proof, $key);
            $now = Date::now()->getTimestamp();
            $leeway = ClientJwt::$leeway;

            if (($claims->htm ?? null) !== $method || ! is_string($claims->htu ?? null)
                || $this->normalizeUri($claims->htu) !== $uri
                || ! is_string($claims->jti ?? null) || $claims->jti === ''
                || (! is_int($claims->iat ?? null) && ! is_float($claims->iat ?? null))
                || $claims->iat > $now + $leeway
                || $now >= $claims->iat + self::PROOF_LIFETIME + $leeway) {
                throw $this->invalidProof();
            }

            $actualThumbprint = $this->thumbprint($header['jwk']);

            if (($thumbprint !== null && ! hash_equals($thumbprint, $actualThumbprint))
                || ($accessToken !== null && (! is_string($claims->ath ?? null)
                    || ! hash_equals(ClientJwt::urlsafeB64Encode(hash('sha256', $accessToken, true)), $claims->ath)))) {
                throw $this->invalidProof();
            }
        } catch (UnsupportedKeyException $exception) {
            throw $this->invalidProof($exception->getMessage());
        } catch (DomainException|InvalidArgumentException|UnexpectedValueException|JsonException) {
            throw $this->invalidProof();
        }

        // Replay tracking covers the same URI across HTTP methods, as recommended by RFC 9449.
        if ($replayProtection && ! $this->replays->consume(
            json_encode(['dpop', $actualThumbprint, $uri, hash('sha256', $claims->jti)], JSON_THROW_ON_ERROR),
            (int) ceil($claims->iat + self::PROOF_LIFETIME + $leeway),
        )) {
            throw $this->invalidProof('The DPoP proof has already been used.');
        }

        if (RequestContext::has()) {
            RequestContext::setValue(self::CONTEXT_KEY, ['inputs' => $inputs, 'thumbprint' => $actualThumbprint]);
        }

        return $actualThumbprint;
    }

    /**
     * Normalize an HTTP target without its query or fragment.
     */
    protected function normalizeUri(string $uri): string
    {
        try {
            $parsed = Uri::of($uri)->getUri();

            if (! in_array($parsed->getScheme(), ['http', 'https'], true)
                || $parsed->getHost() === null || $parsed->getHost() === '' || $parsed->getUserInfo() !== null) {
                throw $this->invalidProof();
            }

            return $parsed->withQuery(null)->withFragment(null)->normalize()->toString();
        } catch (InvalidArgumentException) {
            throw $this->invalidProof();
        }
    }

    /**
     * Hash the validated public JWK's required members in RFC 7638 order.
     *
     * @param array<string, mixed> $jwk
     */
    protected function thumbprint(array $jwk): string
    {
        $members = [];

        foreach ($jwk['kty'] === 'RSA' ? ['e', 'kty', 'n'] : ['crv', 'kty', 'x', 'y'] as $name) {
            $members[$name] = $jwk[$name];
        }

        return ClientJwt::urlsafeB64Encode(hash('sha256', json_encode($members, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), true));
    }

    /**
     * Describe a rejected proof without exposing its signed contents.
     */
    protected function invalidProof(?string $hint = null): OAuthServerException
    {
        return new OAuthServerException('The DPoP proof is invalid.', 9, 'invalid_dpop_proof', 400, $hint);
    }
}
