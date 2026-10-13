<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Unit;

use DateInterval;
use DateTimeImmutable;
use Hypervel\Passport\Bridge\BearerTokenValidator;
use Hypervel\Passport\Bridge\ParsedKey;
use Hypervel\Passport\Bridge\SigningCryptKey;
use Hypervel\Tests\TestCase;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256 as HmacSha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use League\OAuth2\Server\AuthorizationValidators\BearerTokenValidator as LeagueValidator;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\ResourceServer;
use Mockery as m;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;

class BearerTokenValidatorTest extends TestCase
{
    /**
     * @var array<int, array{private: string, public: string}>
     */
    protected static array $keys = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$keys === []) {
            for ($index = 0; $index < 2; ++$index) {
                $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
                openssl_pkey_export($key, $privateKey);
                self::$keys[] = ['private' => $privateKey, 'public' => openssl_pkey_get_details($key)['key']];
            }
        }
    }

    #[DataProvider('validationCases')]
    public function testValidationMatchesLeague(string $scenario, bool $valid): void
    {
        $repository = m::mock(AccessTokenRepositoryInterface::class);

        if ($scenario === 'valid' || $scenario === 'revoked') {
            $repository->shouldReceive('isAccessTokenRevoked')->with('token-id')->twice()->andReturn($scenario === 'revoked');
        } else {
            $repository->shouldNotReceive('isAccessTokenRevoked');
        }

        $public = ParsedKey::fromPublicKey(self::$keys[0]['public']);
        $validator = new BearerTokenValidator($repository, [$public->getKeyId() => $public]);
        $league = new LeagueValidator($repository);
        $league->setPublicKey(new CryptKey(self::$keys[0]['public']));
        $request = new ServerRequest('GET', 'https://example.test/api');

        if ($scenario !== 'missing header') {
            $request = $request->withHeader('Authorization', match ($scenario) {
                'empty bearer' => 'Bearer ',
                'malformed' => 'Bearer not-a-jwt',
                default => 'Bearer ' . $this->token($scenario),
            });
        }

        foreach ([$league, $validator] as $subject) {
            try {
                $result = $subject->validateAuthorization($request);
                $this->assertTrue($valid, $scenario);
                $this->assertSame('token-id', $result->getAttribute('oauth_access_token_id'));
                $this->assertSame('client-id', $result->getAttribute('oauth_client_id'));
                $this->assertSame('user-id', $result->getAttribute('oauth_user_id'));
                $this->assertSame(['read'], $result->getAttribute('oauth_scopes'));
            } catch (OAuthServerException $exception) {
                $this->assertFalse($valid, $scenario);
                $this->assertSame('access_denied', $exception->getErrorType());
                $this->assertSame(401, $exception->getHttpStatusCode());
            }
        }
    }

    /**
     * Supply the supported validation and rejection paths.
     */
    public static function validationCases(): array
    {
        return [
            ['valid', true],
            ['missing header', false],
            ['empty bearer', false],
            ['malformed', false],
            ['expired', false],
            ['future issue time', false],
            ['future not before', false],
            ['wrong signature', false],
            ['wrong algorithm', false],
            ['revoked', false],
        ];
    }

    public function testRetainedKeysVerifyNamedAndLegacyTokensThroughTheResourceServer(): void
    {
        $repository = m::mock(AccessTokenRepositoryInterface::class);
        $repository->shouldReceive('isAccessTokenRevoked')->with('token-id')->times(3)->andReturnFalse();
        $current = ParsedKey::fromPublicKey(self::$keys[1]['public']);
        $retained = ParsedKey::fromPublicKey(self::$keys[0]['public']);
        $validator = new BearerTokenValidator($repository, [
            $current->getKeyId() => $current,
            $retained->getKeyId() => $retained,
        ]);
        $server = new ResourceServer($repository, new SigningCryptKey($current), $validator);

        foreach ([
            $this->token('valid', ['kid' => $current->getKeyId()], 1),
            $this->token('valid', ['kid' => $retained->getKeyId()]),
            $this->token('valid'),
        ] as $token) {
            $response = $server->validateAuthenticatedRequest(new ServerRequest('GET', 'https://example.test', ['Authorization' => 'Bearer ' . $token]));
            $this->assertSame('token-id', $response->getAttribute('oauth_access_token_id'));
        }
    }

    #[DataProvider('invalidKeyIds')]
    public function testInvalidKeyIdsCannotFallBackToAnotherKey(mixed $identifier): void
    {
        $repository = m::mock(AccessTokenRepositoryInterface::class);
        $repository->shouldNotReceive('isAccessTokenRevoked');
        $public = ParsedKey::fromPublicKey(self::$keys[0]['public']);
        $validator = new BearerTokenValidator($repository, [$public->getKeyId() => $public]);
        $request = new ServerRequest('GET', 'https://example.test', [
            'Authorization' => 'Bearer ' . $this->token('valid', ['kid' => $identifier]),
        ]);

        $this->expectException(OAuthServerException::class);

        $validator->validateAuthorization($request);
    }

    /**
     * Supply unknown and malformed key identifiers.
     */
    public static function invalidKeyIds(): array
    {
        return [['unknown'], [null], [42], [[]]];
    }

    public function testConfiguredLeewayAppliesToTokenTimes(): void
    {
        $repository = m::mock(AccessTokenRepositoryInterface::class);
        $repository->shouldReceive('isAccessTokenRevoked')->with('token-id')->once()->andReturnFalse();
        $public = ParsedKey::fromPublicKey(self::$keys[0]['public']);
        $validator = new BearerTokenValidator($repository, [$public->getKeyId() => $public], new DateInterval('PT120S'));

        $result = $validator->validateAuthorization(new ServerRequest('GET', 'https://example.test', [
            'Authorization' => 'Bearer ' . $this->token('expired'),
        ]));

        $this->assertSame('token-id', $result->getAttribute('oauth_access_token_id'));
    }

    /**
     * Issue a stock lcobucci token for a validation scenario.
     */
    protected function token(string $scenario, array $headers = [], int $keyIndex = 0): string
    {
        $configuration = $scenario === 'wrong algorithm'
            ? Configuration::forSymmetricSigner(new HmacSha256, InMemory::plainText(str_repeat('a', 32)))
            : Configuration::forAsymmetricSigner(
                new Sha256,
                InMemory::plainText(self::$keys[$scenario === 'wrong signature' ? 1 : $keyIndex]['private']),
                InMemory::plainText(self::$keys[$keyIndex]['public']),
            );

        $builder = $configuration->builder()
            ->identifiedBy('token-id')
            ->permittedFor('client-id')
            ->relatedTo('user-id')
            ->withClaim('scopes', ['read'])
            ->issuedAt(new DateTimeImmutable($scenario === 'future issue time' ? '+1 hour' : '-2 minutes'))
            ->canOnlyBeUsedAfter(new DateTimeImmutable($scenario === 'future not before' ? '+1 hour' : '-2 minutes'))
            ->expiresAt(new DateTimeImmutable($scenario === 'expired' ? '-1 minute' : '+1 hour'));

        foreach ($headers as $name => $value) {
            $builder = $builder->withHeader($name, $value);
        }

        return $builder->getToken($configuration->signer(), $configuration->signingKey())->toString();
    }
}
