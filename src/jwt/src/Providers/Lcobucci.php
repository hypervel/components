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
use SensitiveParameter;
use Throwable;

class Lcobucci extends Provider implements ProviderContract
{
    /**
     * \Lcobucci\JWT\Signer.
     */
    protected Signer $signer;

    /**
     * The configuration that signs and verifies tokens.
     */
    protected Configuration $config;

    /**
     * Whether the provider has no private key, so it can only verify tokens.
     */
    protected bool $verifiesOnly = false;

    /**
     * Create the Lcobucci provider.
     *
     * A given configuration signs and verifies tokens instead of one built from the secret and keys.
     */
    public function __construct(
        #[SensitiveParameter]
        string $secret,
        string $algo,
        #[SensitiveParameter]
        array $keys,
        ?Configuration $config = null,
    ) {
        parent::__construct($secret, $algo, $keys);

        $this->configure($config);
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
        if ($this->verifiesOnly) {
            throw new JwtException('Private key is not set.');
        }

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
    public function decode(#[SensitiveParameter] string $token): array
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
                // lcobucci needs a signing key even to verify, so a provider without a private
                // key fills the slot with the public key, and encode() refuses to sign.
                $this->verifiesOnly ? $this->getVerificationKey() : $this->getSigningKey(),
                $this->getVerificationKey()
            )
            : Configuration::forSymmetricSigner($this->signer, $this->getSigningKey());

        return $config->withValidationConstraints(
            new SignedWith($this->signer, $this->getVerificationKey())
        );
    }

    /**
     * Build the signer, and the configuration unless one is given.
     *
     * @throws JwtException
     */
    protected function configure(?Configuration $config = null): void
    {
        // buildConfig() reads the signer and the verification-only flag.
        $this->signer = $this->getSigner();
        $this->verifiesOnly = $config === null && $this->isAsymmetric() && ! $this->getPrivateKey();
        $this->config = $config ?? $this->buildConfig();
    }

    /**
     * Rebuild the signer and configuration after a setting changes.
     *
     * A configuration given to the constructor is replaced by one built from the new settings.
     *
     * @throws JwtException
     */
    protected function onConfigurationChanged(): void
    {
        $this->configure();
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
    protected function getKey(#[SensitiveParameter] string $contents, #[SensitiveParameter] string $passphrase = ''): Key
    {
        if (str_starts_with($contents, 'file://')) {
            return InMemory::file($contents, $passphrase);
        }

        return InMemory::plainText($contents, $passphrase);
    }
}
