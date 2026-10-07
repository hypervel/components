<?php

declare(strict_types=1);

namespace Hypervel\Jwt\Providers;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use Hypervel\Jwt\Contracts\ProviderContract;
use Hypervel\Jwt\Exceptions\JwtException;
use Hypervel\Jwt\Exceptions\SecretMissingException;
use Hypervel\Jwt\Exceptions\TokenInvalidException;
use Hypervel\Support\Facades\Date;
use Lcobucci\JWT\Builder;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer;
use Lcobucci\JWT\Signer\Ecdsa;
use Lcobucci\JWT\Signer\Ecdsa\ConversionFailed;
use Lcobucci\JWT\Signer\Key;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Token\RegisteredClaims;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Throwable;

class Lcobucci extends Provider implements ProviderContract
{
    /**
     * \Lcobucci\JWT\Signer.
     */
    protected Signer $signer;

    /**
     * \Lcobucci\JWT\Configuration.
     */
    protected Configuration $config;

    /**
     * Create the Lcobucci provider.
     */
    public function __construct(string $secret, string $algo, array $keys, ?Configuration $config = null)
    {
        parent::__construct($secret, $algo, $keys);

        $this->signer = $this->getSigner();
        $this->config = $config ?: $this->buildConfig();
    }

    /**
     * Signers that this provider supports.
     */
    protected array $signers = [
        self::ALGO_HS256 => Signer\Hmac\Sha256::class,
        self::ALGO_HS384 => Signer\Hmac\Sha384::class,
        self::ALGO_HS512 => Signer\Hmac\Sha512::class,
        self::ALGO_RS256 => Signer\Rsa\Sha256::class,
        self::ALGO_RS384 => Signer\Rsa\Sha384::class,
        self::ALGO_RS512 => Signer\Rsa\Sha512::class,
        self::ALGO_ES256 => Signer\Ecdsa\Sha256::class,
        self::ALGO_ES384 => Signer\Ecdsa\Sha384::class,
        self::ALGO_ES512 => Signer\Ecdsa\Sha512::class,
    ];

    /**
     * Create a JSON Web Token.
     *
     * @throws JwtException
     */
    public function encode(array $payload): string
    {
        $builder = $this->getBuilderFromClaims($payload);

        try {
            return $builder
                ->getToken($this->config->signer(), $this->config->signingKey())
                ->toString();
        } catch (Exception $e) {
            throw new JwtException('Could not create token: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Decode a JSON Web Token.
     *
     * @throws JwtException
     */
    public function decode(string $token): array
    {
        try {
            /** @var Plain */
            $token = $this->config->parser()->parse($token);
        } catch (Throwable $exception) {
            throw new TokenInvalidException(
                'Could not decode token: ' . $exception->getMessage(),
                $exception->getCode(),
                $exception,
            );
        }

        try {
            $verified = $this->config->validator()->validate($token, ...$this->config->validationConstraints());
        } catch (ConversionFailed) {
            // ECDSA verification rejects a signature of the wrong length before comparing it.
            $verified = false;
        }

        if (! $verified) {
            throw new TokenInvalidException('Token Signature could not be verified.');
        }

        return array_map(
            static fn (mixed $claim): mixed => $claim instanceof DateTimeInterface ? $claim->getTimestamp() : $claim,
            $token->claims()->all(),
        );
    }

    /**
     * Create an instance of the builder with all of the claims applied.
     */
    protected function getBuilderFromClaims(array $payload): Builder
    {
        $builder = $this->config->builder();

        foreach ($payload as $key => $value) {
            switch ($key) {
                case RegisteredClaims::ID:
                    $builder = $builder->identifiedBy($value);
                    break;
                case RegisteredClaims::EXPIRATION_TIME:
                    $builder = $builder->expiresAt($this->getDateFromClaim($value));
                    break;
                case RegisteredClaims::NOT_BEFORE:
                    $builder = $builder->canOnlyBeUsedAfter($this->getDateFromClaim($value));
                    break;
                case RegisteredClaims::ISSUED_AT:
                    $builder = $builder->issuedAt($this->getDateFromClaim($value));
                    break;
                case RegisteredClaims::ISSUER:
                    $builder = $builder->issuedBy($value);
                    break;
                case RegisteredClaims::AUDIENCE:
                    $builder = is_array($value)
                        ? $builder->permittedFor(...$value)
                        : $builder->permittedFor($value);
                    break;
                case RegisteredClaims::SUBJECT:
                    $builder = $builder->relatedTo((string) $value);
                    break;
                default:
                    $builder = $builder->withClaim($key, $value);
            }
        }

        return $builder;
    }

    /**
     * Convert a date claim value to a date with whole-second precision.
     */
    protected function getDateFromClaim(int|string|DateTimeInterface|DateInterval $value): DateTimeImmutable
    {
        if ($value instanceof DateInterval) {
            $value = Date::now()->add($value);
        }

        if ($value instanceof DateTimeInterface) {
            $value = $value->getTimestamp();
        }

        return DateTimeImmutable::createFromFormat('U', (string) $value);
    }

    /**
     * Build the configuration.
     */
    protected function buildConfig(): Configuration
    {
        $config = $this->isAsymmetric()
            ? Configuration::forAsymmetricSigner(
                $this->signer,
                $this->getSigningKey(),
                $this->getVerificationKey()
            )
            : Configuration::forSymmetricSigner($this->signer, $this->getSigningKey());

        return $config->withValidationConstraints(
            new SignedWith($this->signer, $this->getVerificationKey())
        );
    }

    /**
     * Rebuild cached derived state after a configuration change.
     *
     * Signer is rebuilt before config because buildConfig() reads $this->signer.
     *
     * @throws JwtException
     */
    protected function onConfigurationChanged(): void
    {
        $this->signer = $this->getSigner();
        $this->config = $this->buildConfig();
    }

    /**
     * Get the signer instance.
     *
     * @throws JwtException
     */
    protected function getSigner(): Signer
    {
        if (! array_key_exists($this->algo, $this->signers)) {
            throw new JwtException('The given algorithm could not be found');
        }

        $signer = $this->signers[$this->algo];

        return new $signer;
    }

    /**
     * Determine if the algorithm is asymmetric, and thus requires a public/private key combo.
     */
    protected function isAsymmetric(): bool
    {
        return is_subclass_of($this->signer, Rsa::class)
            || is_subclass_of($this->signer, Ecdsa::class);
    }

    /**
     * Get the key used to sign the tokens.
     *
     * @throws JwtException
     */
    protected function getSigningKey(): Key
    {
        if ($this->isAsymmetric()) {
            if (! $privateKey = $this->getPrivateKey()) {
                throw new JwtException('Private key is not set.');
            }

            return $this->getKey($privateKey, $this->getPassphrase() ?? '');
        }

        if (! $secret = $this->getSecret()) {
            throw new SecretMissingException('Secret is not set.');
        }

        return $this->getKey($secret);
    }

    /**
     * Get the key used to verify the tokens.
     *
     * @throws JwtException
     */
    protected function getVerificationKey(): Key
    {
        if ($this->isAsymmetric()) {
            if (! $public = $this->getPublicKey()) {
                throw new JwtException('Public key is not set.');
            }

            return $this->getKey($public);
        }

        if (! $secret = $this->getSecret()) {
            throw new SecretMissingException('Secret is not set.');
        }

        return $this->getKey($secret);
    }

    /**
     * Get the signing key instance.
     */
    protected function getKey(string $contents, string $passphrase = ''): Key
    {
        if (str_starts_with($contents, 'file://')) {
            return InMemory::file($contents, $passphrase);
        }

        return InMemory::plainText($contents, $passphrase);
    }
}
