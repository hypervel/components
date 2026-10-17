<?php

declare(strict_types=1);

namespace Hypervel\Passport\Bridge;

use DateTimeImmutable;
use Hypervel\Contracts\Container\Transient;
use Hypervel\Passport\Passport;
use Hypervel\Passport\ServerFactory;
use Hypervel\Passport\Token;
use InvalidArgumentException;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token as JwtToken;
use League\OAuth2\Server\CryptKeyInterface;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Entities\Traits\AccessTokenTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;
use SensitiveParameter;

class AccessToken implements AccessTokenEntityInterface, Transient
{
    use AccessTokenTrait;
    use EntityTrait;
    use TokenEntityTrait;

    /**
     * The model saved when this token was issued.
     */
    protected ?Token $token = null;

    /**
     * The signed representation shared by the response and its extensions.
     */
    protected ?string $serializedToken = null;

    /**
     * Create a new token instance.
     *
     * @param null|non-empty-string $userIdentifier
     * @param ScopeEntityInterface[] $scopes
     */
    public function __construct(?string $userIdentifier, array $scopes, ClientEntityInterface $client)
    {
        if (! is_null($userIdentifier)) {
            $this->setUserIdentifier($userIdentifier);
        }

        foreach ($scopes as $scope) {
            $this->addScope($scope);
        }

        $this->setClient($client);
    }

    /**
     * Set the token identifier.
     *
     * @param non-empty-string $identifier
     */
    public function setIdentifier(string $identifier): void
    {
        $this->identifier = $identifier;
        $this->serializedToken = null;
    }

    /**
     * Set the date time when the token expires.
     */
    public function setExpiryDateTime(DateTimeImmutable $dateTime): void
    {
        $this->expiryDateTime = $dateTime;
        $this->serializedToken = null;
    }

    /**
     * Set the identifier of the user associated with the token.
     *
     * @param non-empty-string $identifier The identifier of the user
     */
    public function setUserIdentifier(string $identifier): void
    {
        $this->userIdentifier = $identifier;
        $this->serializedToken = null;
    }

    /**
     * Set the client that the token was issued to.
     */
    public function setClient(ClientEntityInterface $client): void
    {
        $this->client = $client;
        $this->serializedToken = null;
    }

    /**
     * Associate a scope with the token.
     */
    public function addScope(ScopeEntityInterface $scope): void
    {
        $this->scopes[$scope->getIdentifier()] = $scope;
        $this->serializedToken = null;
    }

    /**
     * Set the private key used to sign this access token.
     */
    public function setPrivateKey(#[SensitiveParameter] CryptKeyInterface $privateKey): void
    {
        $this->privateKey = $privateKey;
        $this->serializedToken = null;
    }

    /**
     * Get the model saved when this token was issued.
     */
    public function getToken(): ?Token
    {
        return $this->token;
    }

    /**
     * Set the model saved when this token was issued.
     */
    public function setToken(Token $token): void
    {
        $this->token = $token;
        $this->serializedToken = null;
    }

    /**
     * Initialize the JWT configuration.
     */
    public function initJwtConfiguration(): void
    {
        $key = $this->privateKey instanceof SigningCryptKey
            ? $this->privateKey->getParsedKey()
            : ParsedKey::fromPrivateKey($this->privateKey->getKeyContents(), $this->privateKey->getPassPhrase() ?? '');

        $this->jwtConfiguration = Configuration::forAsymmetricSigner(
            new RsaSigner,
            $key,
            InMemory::plainText('empty', 'empty')
        );
    }

    /**
     * Generate a JWT from the access token.
     */
    private function convertToJWT(): JwtToken
    {
        $this->initJwtConfiguration();

        /** @var ParsedKey $key */
        $key = $this->jwtConfiguration->signingKey();

        $builder = $this->jwtConfiguration->builder()
            ->permittedFor(...($this->token?->audience ?: [$this->getClient()->getIdentifier()]))
            ->withClaim('client_id', $this->getClient()->getIdentifier())
            ->identifiedBy($this->getIdentifier())
            ->issuedAt(new DateTimeImmutable)
            ->canOnlyBeUsedAfter(new DateTimeImmutable)
            ->expiresAt($this->getExpiryDateTime())
            ->relatedTo($this->getSubjectIdentifier())
            ->withClaim('scopes', $this->getScopes())
            ->withHeader('kid', $key->getKeyId());

        if (($issuer = app(ServerFactory::class)->settings()->issuer) !== null) {
            $builder = $builder->issuedBy($issuer);
        }

        if ($callback = Passport::$accessTokenClaimsCallback) {
            foreach ($callback($this->token) as $name => $value) {
                if (in_array($name, ['iss', 'sub', 'aud', 'exp', 'iat', 'nbf', 'jti', 'scopes', 'client_id', 'cnf'], true)) {
                    throw new InvalidArgumentException("The access token claim [{$name}] is reserved.");
                }

                $builder = $builder->withClaim($name, $value);
            }
        }

        TokenIssuance::current()?->retainSigningKey($this->getExpiryDateTime());

        return $builder->getToken($this->jwtConfiguration->signer(), $key);
    }

    /**
     * Generate a string representation from the access token.
     */
    public function toString(): string
    {
        // Response extensions must hash the exact token returned to the client.
        return $this->serializedToken ??= $this->convertToJWT()->toString();
    }
}
