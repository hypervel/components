<?php

declare(strict_types=1);

namespace Hypervel\Tests\Jwt;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\UserProvider;
use Hypervel\Jwt\ClaimFactory;
use Hypervel\Jwt\Contracts\JwtSubject;
use Hypervel\Jwt\Exceptions\JwtException;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('customRegisteredClaimTtlProvider')]
    public function testCustomClaimsOverrideDefaultRegisteredClaims(?int $ttl): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-01 00:00:00'));

        $claims = $this->factory(['jwt' => ['issuer' => 'https://api.example.test', 'lock_subject' => false]])->make(
            new ClaimFactoryJwtSubjectUser(42, 'jwt-42', ['exp' => 1767229200, 'iss' => 'https://model.example.test']),
            new ClaimFactoryProvider,
            $ttl,
            ['iss' => 'https://tenant.example.test', 'iat' => 1767225000, 'nbf' => 1767226000, 'jti' => 'custom-jti'],
        );

        $this->assertSame(1767229200, $claims['exp']);
        $this->assertSame('https://tenant.example.test', $claims['iss']);
        $this->assertSame(1767225000, $claims['iat']);
        $this->assertSame(1767226000, $claims['nbf']);
        $this->assertSame('custom-jti', $claims['jti']);
    }

    /**
     * Provide token lifetimes that an explicit expiration replaces.
     *
     * @return array<string, array{null|int}>
     */
    public static function customRegisteredClaimTtlProvider(): array
    {
        return [
            'configured lifetime' => [120],
            'no expiration' => [null],
        ];
    }

    public function testRejectsReservedJwtSubjectClaims(): void
    {
        $this->expectException(JwtException::class);
        $this->expectExceptionMessageIs('Custom JWT claims may not override reserved claims: sub.');

        $this->factory()->make(
            new ClaimFactoryJwtSubjectUser(42, 'jwt-42', ['sub' => 999, 'exp' => 123]),
            new ClaimFactoryProvider,
            120,
        );
    }

    public function testRejectsReservedInlineClaims(): void
    {
        $this->expectException(JwtException::class);
        $this->expectExceptionMessageIs('Custom JWT claims may not override reserved claims: prv.');

        $this->factory()->make(
            new ClaimFactoryUser(42),
            new ClaimFactoryProvider,
            120,
            ['iss' => 'https://tenant.example.test', 'prv' => 'fake-provider'],
        );
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

    #[DataProvider('repeatedRefreshIssuedAtProvider')]
    public function testRefreshIssuedAtSettingAppliesOnEveryRefresh(
        bool $refreshIssuedAt,
        int $firstIssuedAt,
        int $secondIssuedAt,
    ): void {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-01 00:00:00'));

        $factory = $this->factory();
        $payload = ['sub' => 42, 'iat' => 100];

        $first = $factory->refresh(
            payload: $payload,
            ttl: null,
            refreshIssuedAt: $refreshIssuedAt,
            resetClaims: false,
            persistentClaims: [],
        );

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(2));

        $second = $factory->refresh(
            payload: $payload,
            ttl: null,
            refreshIssuedAt: $refreshIssuedAt,
            resetClaims: false,
            persistentClaims: [],
        );

        $this->assertSame($firstIssuedAt, $first['iat']);
        $this->assertSame($secondIssuedAt, $second['iat']);
    }

    /**
     * Provide each refresh issued-at setting and the iat of two refreshes two minutes apart.
     *
     * @return array<string, array{bool, int, int}>
     */
    public static function repeatedRefreshIssuedAtProvider(): array
    {
        return [
            'keep original iat' => [false, 100, 100],
            'refresh iat' => [true, 1767225600, 1767225720],
        ];
    }

    #[DataProvider('refreshIssuerProvider')]
    public function testRefreshCarriesTheIssuerLikeOtherClaims(
        bool $resetClaims,
        array $persistentClaims,
        array $customClaims,
        ?string $expectedIssuer,
    ): void {
        $claims = $this->factory()->refresh(
            payload: ['sub' => 42, 'iat' => 100, 'iss' => 'https://tenant.example.test'],
            ttl: 120,
            refreshIssuedAt: false,
            resetClaims: $resetClaims,
            persistentClaims: $persistentClaims,
            customClaims: $customClaims,
        );

        $this->assertSame($expectedIssuer, $claims['iss'] ?? null);
    }

    /**
     * Provide refresh options and the issuer each one produces.
     *
     * @return array<string, array{bool, array<int, string>, array<string, string>, null|string}>
     */
    public static function refreshIssuerProvider(): array
    {
        return [
            'normal refresh' => [false, [], [], 'https://tenant.example.test'],
            'reset without persistence' => [true, [], [], null],
            'reset with persistence' => [true, ['iss'], [], 'https://tenant.example.test'],
            'explicit replacement' => [false, [], ['iss' => 'https://other.example.test'], 'https://other.example.test'],
        ];
    }

    #[DataProvider('refreshIssuedAtProvider')]
    public function testRefreshCustomClaimsOverrideRebuiltClaims(bool $refreshIssuedAt): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-01 00:00:00'));

        $claims = $this->factory()->refresh(
            payload: ['sub' => 42, 'iat' => 100, 'nbf' => 100, 'exp' => 200, 'jti' => 'old-jti'],
            ttl: 120,
            refreshIssuedAt: $refreshIssuedAt,
            resetClaims: false,
            persistentClaims: [],
            customClaims: ['iat' => 1767225000, 'nbf' => 1767226000, 'exp' => 1767229200, 'jti' => 'custom-jti'],
        );

        $this->assertSame(1767225000, $claims['iat']);
        $this->assertSame(1767226000, $claims['nbf']);
        $this->assertSame(1767229200, $claims['exp']);
        $this->assertSame('custom-jti', $claims['jti']);
    }

    /**
     * Provide both refresh issued-at settings.
     *
     * @return array<string, array{bool}>
     */
    public static function refreshIssuedAtProvider(): array
    {
        return [
            'keep original iat' => [false],
            'refresh iat' => [true],
        ];
    }

    public function testRejectsReservedRefreshClaims(): void
    {
        $this->expectException(JwtException::class);
        $this->expectExceptionMessageIs('Custom JWT claims may not override reserved claims: prv, sub.');

        $this->factory()->refresh(
            payload: ['sub' => 42, 'iat' => 100],
            ttl: 120,
            refreshIssuedAt: false,
            resetClaims: false,
            persistentClaims: [],
            customClaims: ['sub' => 7, 'prv' => 'other-provider'],
        );
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

class ClaimFactoryJwtSubjectUser extends ClaimFactoryUser implements JwtSubject
{
    public function __construct(
        int|string $id,
        private readonly mixed $jwtIdentifier,
        private readonly array $customClaims,
    ) {
        parent::__construct($id);
    }

    public function getJwtIdentifier(): mixed
    {
        return $this->jwtIdentifier;
    }

    public function getJwtCustomClaims(): array
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
