<?php

declare(strict_types=1);

namespace Hypervel\Tests\JWT;

use Carbon\CarbonImmutable;
use Hypervel\Config\Repository;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\UserProvider;
use Hypervel\JWT\ClaimFactory;
use Hypervel\JWT\Contracts\JWTSubject;
use Hypervel\Tests\TestCase;
use ReflectionProperty;
use SensitiveParameter;

class ClaimFactoryTest extends TestCase
{
    public function testBuildsDefaultClaims(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-01 00:00:00'));

        $claims = $this->factory(['jwt' => ['issuer' => 'https://api.example.test', 'lock_subject' => true]])
            ->make(new ClaimFactoryUser(42), new ClaimFactoryModelProvider(ClaimFactoryUser::class), 120);

        $this->assertSame(42, $claims['sub']);
        $this->assertSame('https://api.example.test', $claims['iss']);
        $this->assertSame(1767225600, $claims['iat']);
        $this->assertSame(1767225600, $claims['nbf']);
        $this->assertSame(1767232800, $claims['exp']);
        $this->assertSame(hash('xxh128', ClaimFactoryUser::class), $claims['prv']);
    }

    public function testOmitsExpirationWhenTtlIsNull(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-01 00:00:00'));

        $claims = $this->factory()->make(new ClaimFactoryUser(42), new ClaimFactoryProvider, null);

        $this->assertArrayNotHasKey('exp', $claims);
    }

    public function testUsesJwtSubjectIdentifierAndCustomClaims(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-01 00:00:00'));

        $claims = $this->factory()->make(
            new ClaimFactoryJwtSubjectUser(42, 'jwt-42', ['role' => 'user', 'tenant' => 'one']),
            new ClaimFactoryProvider,
            120,
            ['role' => 'admin'],
        );

        $this->assertSame('jwt-42', $claims['sub']);
        $this->assertSame('admin', $claims['role']);
        $this->assertSame('one', $claims['tenant']);
    }

    public function testSubjectMatchingHonorsProviderLock(): void
    {
        $factory = $this->factory(['jwt' => ['issuer' => null, 'lock_subject' => true]]);
        $provider = new ClaimFactoryModelProvider(ClaimFactoryUser::class);

        $this->assertTrue($factory->subjectMatchesProvider([
            'prv' => hash('xxh128', ClaimFactoryUser::class),
        ], $provider));
        $this->assertFalse($factory->subjectMatchesProvider([
            'prv' => hash('xxh128', ClaimFactoryJwtSubjectUser::class),
        ], $provider));
        $this->assertFalse($factory->subjectMatchesProvider([], $provider));
    }

    public function testSubjectMatchingSkipsProvidersWithoutModel(): void
    {
        $factory = $this->factory(['jwt' => ['issuer' => null, 'lock_subject' => true]]);

        $this->assertTrue($factory->subjectMatchesProvider([], new ClaimFactoryProvider));
    }

    public function testRefreshKeepsPersistentClaimsAndDropsManagedClaims(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-01 00:00:00'));

        $claims = $this->factory(['jwt' => ['issuer' => 'https://api.example.test', 'lock_subject' => true]])
            ->refresh(
                payload: [
                    'sub' => 42,
                    'iat' => 100,
                    'nbf' => 100,
                    'exp' => 200,
                    'iss' => 'old-issuer',
                    'jti' => 'old-jti',
                    'prv' => 'provider-hash',
                    'role' => 'user',
                    'tenant' => 'one',
                ],
                ttl: 120,
                refreshIssuedAt: false,
                resetClaims: true,
                persistentClaims: ['tenant', 'exp', 'jti'],
                customClaims: ['role' => 'admin'],
            );

        $this->assertSame(42, $claims['sub']);
        $this->assertSame(100, $claims['iat']);
        $this->assertSame(1767225600, $claims['nbf']);
        $this->assertSame(1767232800, $claims['exp']);
        $this->assertSame('https://api.example.test', $claims['iss']);
        $this->assertSame('provider-hash', $claims['prv']);
        $this->assertSame('one', $claims['tenant']);
        $this->assertSame('admin', $claims['role']);
        $this->assertArrayNotHasKey('jti', $claims);
    }

    public function testRefreshKeepsNonManagedClaimsWhenResetClaimsIsFalse(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-01 00:00:00'));

        $claims = $this->factory(['jwt' => ['issuer' => null, 'lock_subject' => true]])
            ->refresh(
                payload: [
                    'sub' => 42,
                    'iat' => 100,
                    'nbf' => 100,
                    'exp' => 200,
                    'jti' => 'old-jti',
                    'role' => 'user',
                    'tenant' => 'one',
                ],
                ttl: null,
                refreshIssuedAt: false,
                resetClaims: false,
                persistentClaims: [],
                customClaims: ['role' => 'admin'],
            );

        $this->assertSame(42, $claims['sub']);
        $this->assertSame(100, $claims['iat']);
        $this->assertSame(1767225600, $claims['nbf']);
        $this->assertArrayNotHasKey('exp', $claims);
        $this->assertArrayNotHasKey('jti', $claims);
        $this->assertSame('admin', $claims['role']);
        $this->assertSame('one', $claims['tenant']);
    }

    public function testRefreshIssuedAtRestampsIat(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-01 00:00:00'));

        $claims = $this->factory()->refresh(
            payload: ['sub' => 42, 'iat' => 100],
            ttl: null,
            refreshIssuedAt: true,
            resetClaims: false,
            persistentClaims: [],
        );

        $this->assertSame(1767225600, $claims['iat']);
    }

    public function testFlushStateClearsModelHashCache(): void
    {
        $factory = $this->factory(['jwt' => ['issuer' => null, 'lock_subject' => true]]);

        $factory->make(new ClaimFactoryUser(42), new ClaimFactoryModelProvider(ClaimFactoryUser::class), 120);

        $property = new ReflectionProperty(ClaimFactory::class, 'subjectModelHashes');
        $this->assertSame([
            ClaimFactoryUser::class => hash('xxh128', ClaimFactoryUser::class),
        ], $property->getValue());

        ClaimFactory::flushState();

        $this->assertSame([], $property->getValue());
    }

    /**
     * Create a claim factory.
     */
    private function factory(array $config = ['jwt' => ['issuer' => null, 'lock_subject' => false]]): ClaimFactory
    {
        return new ClaimFactory(new Repository($config));
    }
}

class ClaimFactoryUser implements Authenticatable
{
    public function __construct(
        private readonly int|string $id,
    ) {
    }

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->id;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): ?string
    {
        return null;
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken(string $value): void
    {
    }

    public function getRememberTokenName(): string
    {
        return 'remember_token';
    }
}

class ClaimFactoryJwtSubjectUser extends ClaimFactoryUser implements JWTSubject
{
    public function __construct(
        int|string $id,
        private readonly mixed $jwtIdentifier,
        private readonly array $customClaims,
    ) {
        parent::__construct($id);
    }

    public function getJWTIdentifier(): mixed
    {
        return $this->jwtIdentifier;
    }

    public function getJWTCustomClaims(): array
    {
        return $this->customClaims;
    }
}

class ClaimFactoryProvider implements UserProvider
{
    public function retrieveById(mixed $identifier): ?Authenticatable
    {
        return null;
    }

    public function retrieveByToken(mixed $identifier, #[SensitiveParameter] string $token): ?Authenticatable
    {
        return null;
    }

    public function updateRememberToken(Authenticatable $user, #[SensitiveParameter] string $token): void
    {
    }

    public function retrieveByCredentials(#[SensitiveParameter] array $credentials): ?Authenticatable
    {
        return null;
    }

    public function validateCredentials(Authenticatable $user, #[SensitiveParameter] array $credentials): bool
    {
        return false;
    }

    public function rehashPasswordIfRequired(
        Authenticatable $user,
        #[SensitiveParameter]
        array $credentials,
        bool $force = false,
    ): void {
    }
}

class ClaimFactoryModelProvider extends ClaimFactoryProvider
{
    public function __construct(
        private readonly string $model,
    ) {
    }

    public function getModel(): string
    {
        return $this->model;
    }
}
