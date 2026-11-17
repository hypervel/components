<?php

declare(strict_types=1);

namespace Hypervel\Tests\Jwt;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Container\Container;
use Hypervel\Foundation\Application;
use Hypervel\Jwt\ClaimFactory;
use Hypervel\Jwt\Contracts\BlacklistContract;
use Hypervel\Jwt\Contracts\ValidationContract;
use Hypervel\Jwt\Exceptions\JwtException;
use Hypervel\Jwt\Exceptions\TokenBlacklistedException;
use Hypervel\Jwt\Exceptions\TokenExpiredException;
use Hypervel\Jwt\Exceptions\TokenInvalidException;
use Hypervel\Jwt\JwtManager;
use Hypervel\Jwt\Providers\Lcobucci;
use Hypervel\Jwt\Providers\Provider;
use Hypervel\Jwt\Validations\ExpiredClaim;
use Hypervel\Jwt\Validations\IssuedAtClaim;
use Hypervel\Jwt\Validations\IssuerClaim;
use Hypervel\Jwt\Validations\NotBeforeClaim;
use Hypervel\Jwt\Validations\RequiredClaims;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\Str;
use Hypervel\Tests\Jwt\Fixtures\ValidationStub;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\DoubleInterface;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use JMac\Testing\OverriddenDouble;
use PHPUnit\Framework\Attributes\DataProvider;
use SensitiveParameterValue;
use Symfony\Component\Uid\Uuid;

class JwtManagerTest extends TestCase
{
    use VerifiesDoubles;

    private OverriddenDouble $container;

    private Repository&DoubleInterface $config;

    private Lcobucci&DoubleInterface $provider;

    private BlacklistContract&DoubleInterface $blacklist;

    private ClaimFactory&DoubleInterface $claimFactory;

    private int $testNowTimestamp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setTestNow();
        $this->mockContainer();
        $this->mockConfig();
        $this->mockProvider();
        $this->mockBlacklist();
        $this->mockClaimFactory();
    }

    public function testEncodeAPayload(): void
    {
        $jti = '11111111-1111-4111-8111-111111111111';
        $token = 'foo.bar.baz';
        $payload = [
            'sub' => 1,
            'iss' => 'http://example.com',
            'exp' => $this->testNowTimestamp + 3600,
            'nbf' => $this->testNowTimestamp,
            'iat' => $this->testNowTimestamp,
            'jti' => $jti,
        ];

        $this->mockUuid($jti);

        $this->config->allows('boolean')->with('jwt.blacklist_enabled')->returns(true);
        $this->provider->allows('encode')->with($payload)->returns($token);

        $this->assertEquals($token, $this->createManager()->encode($payload));
    }

    public function testEncodeAddsJtiWhenBlacklistIsEnabledAndMissing(): void
    {
        $token = 'foo.bar.baz';
        $payload = ['sub' => 1, 'iat' => $this->testNowTimestamp];
        $jti = '11111111-1111-4111-8111-111111111111';

        $this->mockUuid($jti);

        $this->config->allows('boolean')->with('jwt.blacklist_enabled')->returns(true);
        $this->provider->expects('encode')->with($payload + ['jti' => $jti])->returns($token);

        $this->assertSame($token, $this->createManager()->encode($payload));
    }

    public function testEncodeDoesNotAddJtiWhenBlacklistIsDisabled(): void
    {
        $token = 'foo.bar.baz';
        $payload = ['sub' => 1, 'iat' => $this->testNowTimestamp];

        $this->config->allows('boolean')->with('jwt.blacklist_enabled')->returns(false);
        $this->provider->expects('encode')->with($payload)->returns($token);

        $this->assertSame($token, $this->createManager()->encode($payload));
    }

    public function testNullSecretSupportsAsymmetricSigning(): void
    {
        $application = new Application;
        $application->instance('config', new Repository([
            'jwt' => [
                'blacklist_enabled' => false,
                'driver' => 'lcobucci',
                'secret' => null,
                'algo' => Provider::ALGO_RS256,
                'keys' => [
                    'private' => file_get_contents(__DIR__ . '/Fixtures/keys/id_rsa'),
                    'public' => file_get_contents(__DIR__ . '/Fixtures/keys/id_rsa.pub'),
                    'passphrase' => null,
                ],
            ],
        ]));

        $manager = new JwtManager($application, Double::for(ClaimFactory::class)->strict());
        $token = $manager->encode([
            'sub' => 1,
            'iat' => $this->testNowTimestamp,
            'custom' => 'value',
        ]);
        $payload = $manager->decode($token, validate: false);

        $this->assertSame('1', $payload['sub']);
        $this->assertSame($this->testNowTimestamp, $payload['iat']);
        $this->assertSame('value', $payload['custom']);
    }

    public function testCustomDriverCanReplaceTheLcobucciDriver(): void
    {
        $this->config->allows('boolean')->with('jwt.blacklist_enabled')->returns(false);
        $this->config->allows('string')->with('jwt.driver')->returns('lcobucci');

        $manager = new JwtManager($this->container->instance(), $this->claimFactory);
        $provider = $this->provider;

        $manager->extend('lcobucci', static fn (): Lcobucci => $provider);

        $this->assertSame($provider, $manager->driver());
    }

    public function testDisabledBlacklistIsNeverResolved(): void
    {
        $token = 'foo.bar.baz';
        $refreshedToken = 'baz.bar.foo';
        $payload = ['sub' => 1, 'iat' => $this->testNowTimestamp];

        $this->mockContainer();
        $this->mockConfig();
        $this->container->expects('make')->with(BlacklistContract::class)->never();

        $this->config->allows('boolean')->with('jwt.blacklist_enabled')->returns(false);
        $this->config->allows('array')->with('jwt.validations')->returns([ValidationStub::class]);
        $this->config->allows('array')->with('jwt')->returns([]);
        $this->config->allows('get')->with('jwt.refresh_ttl')->returns(20160);
        $this->config->allows('get')->with('jwt.ttl')->returns(120);
        $this->config->allows('boolean')->with('jwt.refresh_iat')->returns(false);
        $this->config->allows('array')->with('jwt.persistent_claims')->returns([]);
        $this->claimFactory->expects('refresh')->returns($payload);
        $this->provider->expects('decode')->times(2)->with($token)->returns($payload);
        $this->provider->expects('encode')->with($payload)->returns($refreshedToken);

        $manager = $this->createManager();

        $this->assertFalse($manager->hasBlacklistEnabled());
        $this->assertSame($payload, $manager->decode($token));
        $this->assertSame($refreshedToken, $manager->refresh($token));
    }

    public function testDecodeAToken(): void
    {
        $token = 'foo.bar.baz';
        $payload = [
            'sub' => 1,
            'iss' => 'http://example.com',
            'exp' => $this->testNowTimestamp + 3600,
            'nbf' => $this->testNowTimestamp,
            'iat' => $this->testNowTimestamp,
            'jti' => 'foo',
        ];

        $this->config->allows('boolean')->with('jwt.blacklist_enabled')->returns(true);
        $this->config->allows('array')->with('jwt.validations')->returns([ValidationStub::class]);
        $this->config->allows('array')->with('jwt')->returns([]);
        $this->provider->allows('decode')->with($token)->returns($payload);
        $this->blacklist->allows('has')->with($payload)->returns(false);

        $this->assertSame($payload, $this->createManager()->decode($token));
    }

    public function testThrowExceptionWhenTokenIsBlacklisted(): void
    {
        $this->expectException(TokenBlacklistedException::class);
        $this->expectExceptionMessageIs('The token has been blacklisted');

        $token = 'foo.bar.baz';
        $payload = [
            'sub' => 1,
            'iss' => 'http://example.com',
            'exp' => $this->testNowTimestamp + 3600,
            'nbf' => $this->testNowTimestamp,
            'iat' => $this->testNowTimestamp,
            'jti' => 'foo',
        ];

        $this->config->allows('boolean')->with('jwt.blacklist_enabled')->returns(true);
        $this->config->allows('array')->with('jwt.validations')->returns([ValidationStub::class]);
        $this->config->allows('array')->with('jwt')->returns([]);
        $this->provider->expects('decode')->with($token)->returns($payload);
        $this->blacklist->allows('has')->with($payload)->returns(true);

        $this->createManager()->decode($token);
    }

    public function testRefreshAToken(): void
    {
        $token = 'foo.bar.baz';
        $refreshedToken = 'baz.bar.foo';
        $payload = [
            'sub' => 1,
            'iss' => 'http://example.com',
            'exp' => $this->testNowTimestamp - 3600,
            'nbf' => $this->testNowTimestamp,
            'iat' => $this->testNowTimestamp,
            'jti' => 'foo',
        ];
        $refreshJti = '22222222-2222-4222-8222-222222222222';
        $refreshPayload = [
            'sub' => 1,
            'iss' => 'http://example.com',
            'iat' => $this->testNowTimestamp,
            'exp' => $this->testNowTimestamp + 7200,
            'jti' => $refreshJti,
        ];

        $this->mockUuid($refreshJti);

        $this->config->allows('boolean')->with('jwt.blacklist_enabled')->returns(true);
        $this->config->allows('array')->with('jwt.validations')->returns([ValidationStub::class]);
        $this->config->allows('array')->with('jwt')->returns([]);
        $this->config->allows('get')->with('jwt.refresh_ttl')->returns(20160);
        $this->config->allows('array')->with('jwt.persistent_claims')->returns(['iss']);
        $this->config->allows('get')->with('jwt.ttl')->returns(120);
        $this->config->allows('boolean')->with('jwt.refresh_iat')->returns(false);
        $this->claimFactory->expects('refresh')->with(
            $payload,
            120,
            false,
            false,
            ['iss'],
            [],
        )->returns($refreshPayload);
        $this->provider->expects('decode')->times(2)->with('foo.bar.baz')->returns($payload);
        $this->provider->allows('encode')->with($refreshPayload)->returns($refreshedToken);
        $this->blacklist->allows('has')->with($payload)->returns(false);
        $this->blacklist->expects('add')->with($payload)->returns(true);

        $this->assertSame($refreshedToken, $this->createManager()->refresh($token));
    }

    public function testRefreshDoesNotInvalidateOldTokenWhenEncodingReplacementFails(): void
    {
        $this->expectException(JwtException::class);
        $this->expectExceptionMessageIs('signing failed');

        $token = 'foo.bar.baz';
        $payload = [
            'sub' => 1,
            'iat' => $this->testNowTimestamp,
        ];
        $refreshPayload = [
            'sub' => 1,
            'iat' => $this->testNowTimestamp,
        ];

        $this->config->allows('boolean')->with('jwt.blacklist_enabled')->returns(true);
        $this->config->allows('array')->with('jwt.validations')->returns([RequiredClaims::class]);
        $this->config->allows('array')->with('jwt')->returns(['required_claims' => ['iat', 'sub']]);
        $this->config->allows('get')->with('jwt.refresh_ttl')->returns(20160);
        $this->config->allows('array')->with('jwt.persistent_claims')->returns([]);
        $this->config->allows('get')->with('jwt.ttl')->returns(120);
        $this->config->allows('boolean')->with('jwt.refresh_iat')->returns(false);
        $this->claimFactory->expects('refresh')->returns($refreshPayload);
        $this->provider->expects('decode')->with($token)->returns($payload);
        $this->provider->expects('encode')->with($refreshPayload + ['jti' => '11111111-1111-4111-8111-111111111111'])->throws(new JwtException('signing failed'));
        $this->blacklist->expects('has')->with($payload)->returns(false);
        $this->blacklist->expects('add')->never();

        $this->mockUuid('11111111-1111-4111-8111-111111111111');

        $this->createManager()->refresh($token);
    }

    public function testRefreshOmitsExpirationWhenTtlIsNull(): void
    {
        $token = 'foo.bar.baz';
        $refreshedToken = 'baz.bar.foo';
        $payload = [
            'sub' => 1,
            'iss' => 'http://example.com',
            'exp' => $this->testNowTimestamp - 3600,
            'nbf' => $this->testNowTimestamp,
            'iat' => $this->testNowTimestamp,
            'jti' => 'foo',
        ];
        $refreshPayload = [
            'sub' => 1,
            'iss' => 'http://example.com',
            'iat' => $this->testNowTimestamp,
        ];

        $this->config->allows('boolean')->with('jwt.blacklist_enabled')->returns(false);
        $this->config->allows('array')->with('jwt.validations')->returns([ValidationStub::class]);
        $this->config->allows('array')->with('jwt')->returns([]);
        $this->config->allows('get')->with('jwt.refresh_ttl')->returns(20160);
        $this->config->allows('array')->with('jwt.persistent_claims')->returns(['iss']);
        $this->config->allows('get')->with('jwt.ttl')->returns(null);
        $this->config->allows('boolean')->with('jwt.refresh_iat')->returns(false);
        $this->claimFactory->expects('refresh')->with(
            $payload,
            null,
            false,
            false,
            ['iss'],
            [],
        )->returns($refreshPayload);
        $this->provider->expects('decode')->with('foo.bar.baz')->returns($payload);
        $this->provider->allows('encode')->with($refreshPayload)->returns($refreshedToken);

        $this->assertSame($refreshedToken, $this->createManager()->refresh($token));
    }

    public function testDecodeStillRejectsExpiredTokensWhenExpiredClaimValidationIsEnabled(): void
    {
        $this->expectException(TokenExpiredException::class);
        $this->expectExceptionMessageIs('Token has expired');

        $payload = [
            'sub' => 1,
            'exp' => $this->testNowTimestamp - 3600,
            'iat' => $this->testNowTimestamp,
        ];

        $this->config->allows('boolean')->with('jwt.blacklist_enabled')->returns(false);
        $this->config->allows('array')->with('jwt.validations')->returns([RequiredClaims::class, ExpiredClaim::class]);
        $this->config->allows('array')->with('jwt')->returns(['required_claims' => ['iat', 'sub']]);
        $this->provider->expects('decode')->with('foo.bar.baz')->returns($payload);

        $this->createManager()->decode('foo.bar.baz');
    }

    public function testCustomValidationsAreResolvedFromTheContainerOnceAndReused(): void
    {
        $token = 'foo.bar.baz';
        $payload = ['sub' => 1, 'ver' => 1];
        $versions = new JwtManagerTokenVersions([1 => 1]);
        $resolutions = 0;

        $application = new Application;
        $application->instance('config', new Repository([
            'jwt' => [
                'blacklist_enabled' => false,
                'driver' => 'dummy',
                'validations' => [JwtManagerTokenVersionValidation::class],
            ],
        ]));
        $application->instance(JwtManagerTokenVersions::class, $versions);
        $application->resolving(JwtManagerTokenVersionValidation::class, function () use (&$resolutions): void {
            ++$resolutions;
        });

        $provider = $this->provider;
        $provider->expects('decode')->times(3)->with($token)->returns($payload);

        $manager = new JwtManager($application, $this->claimFactory);
        $manager->extend('dummy', static fn (): Lcobucci => $provider);

        $this->assertSame($payload, $manager->decode($token));
        $this->assertSame($payload, $manager->decode($token));
        $this->assertSame(1, $resolutions);

        $versions->current[1] = 2;

        $this->expectException(TokenInvalidException::class);
        $this->expectExceptionMessageIs('Token version is outdated.');

        $manager->decode($token);
    }

    public function testRefreshSkipsTemporalValidationsInsideRefreshWindow(): void
    {
        $token = 'foo.bar.baz';
        $refreshedToken = 'baz.bar.foo';
        $payload = [
            'sub' => 1,
            'iss' => 'http://example.com',
            'exp' => $this->testNowTimestamp - 3600,
            'nbf' => $this->testNowTimestamp - 7200,
            'iat' => $this->testNowTimestamp - 7200,
        ];
        $refreshPayload = [
            'sub' => 1,
            'iss' => 'http://example.com',
            'iat' => $this->testNowTimestamp - 7200,
            'exp' => $this->testNowTimestamp + 7200,
        ];

        $this->config->allows('boolean')->with('jwt.blacklist_enabled')->returns(false);
        $this->config->allows('array')->with('jwt.validations')->returns([
            RequiredClaims::class,
            ExpiredClaim::class,
            IssuerClaim::class,
            IssuedAtClaim::class,
            NotBeforeClaim::class,
        ]);
        $this->config->allows('array')->with('jwt')->returns([
            'required_claims' => ['iat', 'sub'],
            'issuer' => 'http://example.com',
            'leeway' => 0,
        ]);
        $this->config->allows('get')->with('jwt.refresh_ttl')->returns(20160);
        $this->config->allows('get')->with('jwt.ttl')->returns(120);
        $this->config->allows('boolean')->with('jwt.refresh_iat')->returns(false);
        $this->config->allows('array')->with('jwt.persistent_claims')->returns([]);
        $this->claimFactory->expects('refresh')->with(
            $payload,
            120,
            false,
            false,
            [],
            [],
        )->returns($refreshPayload);
        $this->provider->expects('decode')->with($token)->returns($payload);
        $this->provider->expects('encode')->with($refreshPayload)->returns($refreshedToken);

        $this->assertSame($refreshedToken, $this->createManager()->refresh($token));
    }

    #[DataProvider('futureTimestampClaimProvider')]
    public function testRefreshRejectsFutureTimestampClaimsBeyondTheLeeway(string $validation, string $claim): void
    {
        $this->expectException(TokenInvalidException::class);
        $this->expectExceptionMessageIsOrContains("({$claim}) timestamp cannot be in the future");

        $token = 'foo.bar.baz';
        $payload = [
            'sub' => 1,
            'iat' => $this->testNowTimestamp,
            $claim => $this->testNowTimestamp + 61,
        ];

        $this->config->allows('boolean')->with('jwt.blacklist_enabled')->returns(true);
        $this->config->allows('array')->with('jwt.validations')->returns([RequiredClaims::class, $validation]);
        $this->config->allows('array')->with('jwt')->returns(['required_claims' => ['iat', 'sub'], 'leeway' => 60]);
        $this->provider->expects('decode')->with($token)->returns($payload);
        $this->provider->expects('encode')->never();
        $this->blacklist->expects('add')->never();

        $this->createManager()->refresh($token);
    }

    #[DataProvider('futureTimestampClaimProvider')]
    public function testRefreshAcceptsFutureTimestampClaimsWithinTheLeeway(string $validation, string $claim): void
    {
        $token = 'foo.bar.baz';
        $refreshedToken = 'baz.bar.foo';
        $payload = [
            'sub' => 1,
            'iat' => $this->testNowTimestamp,
            $claim => $this->testNowTimestamp + 60,
        ];
        $refreshPayload = [
            'sub' => 1,
            'iat' => $this->testNowTimestamp,
        ];

        $this->config->allows('boolean')->with('jwt.blacklist_enabled')->returns(false);
        $this->config->allows('array')->with('jwt.validations')->returns([RequiredClaims::class, $validation]);
        $this->config->allows('array')->with('jwt')->returns(['required_claims' => ['iat', 'sub'], 'leeway' => 60]);
        $this->config->allows('get')->with('jwt.refresh_ttl')->returns(20160);
        $this->config->allows('integer')->with('jwt.leeway')->returns(60);
        $this->config->allows('get')->with('jwt.ttl')->returns(120);
        $this->config->allows('boolean')->with('jwt.refresh_iat')->returns(true);
        $this->config->allows('array')->with('jwt.persistent_claims')->returns([]);
        $this->claimFactory->expects('refresh')->with($payload, 120, true, false, [], [])->returns($refreshPayload);
        $this->provider->expects('decode')->with($token)->returns($payload);
        $this->provider->expects('encode')->with($refreshPayload)->returns($refreshedToken);

        $this->assertSame($refreshedToken, $this->createManager()->refresh($token));
    }

    /**
     * Provide validations that reject future timestamps during refresh.
     *
     * @return array<string, array{class-string, string}>
     */
    public static function futureTimestampClaimProvider(): array
    {
        return [
            'not before' => [NotBeforeClaim::class, 'nbf'],
            'issued at' => [IssuedAtClaim::class, 'iat'],
        ];
    }

    public function testRefreshAllowsPastNotBeforeClaim(): void
    {
        $token = 'foo.bar.baz';
        $refreshedToken = 'baz.bar.foo';
        $payload = [
            'sub' => 1,
            'iat' => $this->testNowTimestamp,
            'nbf' => $this->testNowTimestamp - 60,
        ];
        $refreshPayload = [
            'sub' => 1,
            'iat' => $this->testNowTimestamp,
            'nbf' => $this->testNowTimestamp,
        ];

        $this->config->allows('boolean')->with('jwt.blacklist_enabled')->returns(false);
        $this->config->allows('array')->with('jwt.validations')->returns([RequiredClaims::class, NotBeforeClaim::class]);
        $this->config->allows('array')->with('jwt')->returns(['required_claims' => ['iat', 'sub'], 'leeway' => 0]);
        $this->config->allows('get')->with('jwt.refresh_ttl')->returns(20160);
        $this->config->allows('get')->with('jwt.ttl')->returns(120);
        $this->config->allows('boolean')->with('jwt.refresh_iat')->returns(false);
        $this->config->allows('array')->with('jwt.persistent_claims')->returns([]);
        $this->claimFactory->expects('refresh')->with(
            $payload,
            120,
            false,
            false,
            [],
            [],
        )->returns($refreshPayload);
        $this->provider->expects('decode')->with($token)->returns($payload);
        $this->provider->expects('encode')->with($refreshPayload)->returns($refreshedToken);

        $this->assertSame($refreshedToken, $this->createManager()->refresh($token));
    }

    public function testRefreshPassesResetClaimsCustomClaimsAndExplicitTtlToClaimFactory(): void
    {
        $token = 'foo.bar.baz';
        $refreshedToken = 'baz.bar.foo';
        $payload = [
            'sub' => 1,
            'iat' => $this->testNowTimestamp,
        ];
        $refreshPayload = [
            'sub' => 1,
            'tenant' => 'acme',
        ];

        $this->config->allows('boolean')->with('jwt.blacklist_enabled')->returns(false);
        $this->config->allows('array')->with('jwt.validations')->returns([RequiredClaims::class]);
        $this->config->allows('array')->with('jwt')->returns(['required_claims' => ['iat', 'sub']]);
        $this->config->allows('get')->with('jwt.refresh_ttl')->returns(20160);
        $this->config->allows('boolean')->with('jwt.refresh_iat')->returns(true);
        $this->config->allows('array')->with('jwt.persistent_claims')->returns(['tenant']);
        $this->claimFactory->expects('refresh')->with(
            $payload,
            null,
            true,
            true,
            ['tenant'],
            ['tenant' => 'acme'],
        )->returns($refreshPayload);
        $this->provider->expects('decode')->with($token)->returns($payload);
        $this->provider->expects('encode')->with($refreshPayload)->returns($refreshedToken);

        $this->assertSame($refreshedToken, $this->createManager()->refresh(
            token: $token,
            resetClaims: true,
            customClaims: ['tenant' => 'acme'],
            ttl: null,
        ));
    }

    #[DataProvider('expiredRefreshWindowLeewayProvider')]
    public function testRefreshThrowsWhenRefreshWindowHasExpired(int $leeway): void
    {
        $this->expectException(TokenExpiredException::class);
        $this->expectExceptionMessageIs('Token has expired and can no longer be refreshed');

        $token = 'foo.bar.baz';
        $payload = [
            'sub' => 1,
            'iss' => 'http://example.com',
            'exp' => $this->testNowTimestamp - 3600,
            'nbf' => $this->testNowTimestamp,
            'iat' => $this->testNowTimestamp - 660,
            'jti' => 'foo',
        ];

        $this->config->allows('boolean')->with('jwt.blacklist_enabled')->returns(true);
        $this->config->allows('array')->with('jwt.validations')->returns([ValidationStub::class]);
        $this->config->allows('array')->with('jwt')->returns([]);
        $this->config->allows('get')->with('jwt.refresh_ttl')->returns(10);
        $this->config->allows('integer')->with('jwt.leeway')->returns($leeway);
        $this->provider->expects('decode')->with('foo.bar.baz')->returns($payload);
        $this->provider->expects('encode')->never();
        $this->blacklist->allows('has')->with($payload)->returns(false);
        $this->blacklist->expects('add')->never();

        $this->createManager()->refresh($token);
    }

    /**
     * Provide leeways shorter than the 60-second overrun of the refresh window.
     *
     * @return array<string, array{int}>
     */
    public static function expiredRefreshWindowLeewayProvider(): array
    {
        return [
            'no leeway' => [0],
            'leeway shorter than the overrun' => [59],
        ];
    }

    public function testRefreshWindowIncludesTheLeeway(): void
    {
        $token = 'foo.bar.baz';
        $refreshedToken = 'baz.bar.foo';
        $payload = [
            'sub' => 1,
            'exp' => $this->testNowTimestamp - 3600,
            'iat' => $this->testNowTimestamp - 660,
        ];
        $refreshPayload = [
            'sub' => 1,
            'iat' => $this->testNowTimestamp - 660,
            'exp' => $this->testNowTimestamp + 7200,
        ];

        $this->config->allows('boolean')->with('jwt.blacklist_enabled')->returns(false);
        $this->config->allows('array')->with('jwt.validations')->returns([ValidationStub::class]);
        $this->config->allows('array')->with('jwt')->returns([]);
        $this->config->allows('get')->with('jwt.refresh_ttl')->returns(10);
        $this->config->allows('integer')->with('jwt.leeway')->returns(60);
        $this->config->allows('get')->with('jwt.ttl')->returns(120);
        $this->config->allows('boolean')->with('jwt.refresh_iat')->returns(false);
        $this->config->allows('array')->with('jwt.persistent_claims')->returns([]);
        $this->claimFactory->expects('refresh')->with($payload, 120, false, false, [], [])->returns($refreshPayload);
        $this->provider->expects('decode')->with($token)->returns($payload);
        $this->provider->expects('encode')->with($refreshPayload)->returns($refreshedToken);

        $this->assertSame($refreshedToken, $this->createManager()->refresh($token));
    }

    public function testRefreshWindowCanBeDisabled(): void
    {
        $token = 'foo.bar.baz';
        $refreshedToken = 'baz.bar.foo';
        $payload = [
            'sub' => 1,
            'iss' => 'http://example.com',
            'exp' => $this->testNowTimestamp - 3600,
            'nbf' => $this->testNowTimestamp,
            'iat' => $this->testNowTimestamp - 660,
            'jti' => 'foo',
        ];
        $refreshPayload = [
            'sub' => 1,
            'iss' => 'http://example.com',
            'iat' => $this->testNowTimestamp - 660,
            'exp' => $this->testNowTimestamp + 7200,
        ];

        $this->config->allows('boolean')->with('jwt.blacklist_enabled')->returns(false);
        $this->config->allows('array')->with('jwt.validations')->returns([ValidationStub::class]);
        $this->config->allows('array')->with('jwt')->returns([]);
        $this->config->allows('get')->with('jwt.refresh_ttl')->returns(null);
        $this->config->allows('array')->with('jwt.persistent_claims')->returns(['iss']);
        $this->config->allows('get')->with('jwt.ttl')->returns(120);
        $this->config->allows('boolean')->with('jwt.refresh_iat')->returns(false);
        $this->claimFactory->expects('refresh')->with(
            $payload,
            120,
            false,
            false,
            ['iss'],
            [],
        )->returns($refreshPayload);
        $this->provider->expects('decode')->with('foo.bar.baz')->returns($payload);
        $this->provider->allows('encode')->with($refreshPayload)->returns($refreshedToken);

        $this->assertSame($refreshedToken, $this->createManager()->refresh($token));
    }

    #[DataProvider('missingIssuedAtProvider')]
    public function testRefreshRejectsMissingIssuedAtBeforeAnInfiniteRefreshWindow(array $issuedAt): void
    {
        $this->expectException(TokenInvalidException::class);
        $this->expectExceptionMessageIs('Issued At (iat) claim is required to refresh a token.');

        $payload = [
            'sub' => 1,
            'exp' => $this->testNowTimestamp + 3600,
            ...$issuedAt,
        ];

        $this->config->allows('boolean')->with('jwt.blacklist_enabled')->returns(false);
        $this->config->allows('array')->with('jwt.validations')->returns([RequiredClaims::class]);
        $this->config->allows('array')->with('jwt')->returns(['required_claims' => ['sub']]);
        $this->config->allows('get')->with('jwt.refresh_ttl')->returns(null);
        $this->config->allows('get')->with('jwt.ttl')->returns(120);
        $this->config->allows('boolean')->with('jwt.refresh_iat')->returns(true);
        $this->config->allows('array')->with('jwt.persistent_claims')->returns([]);
        $this->claimFactory->expects('refresh')->never();
        $this->provider->expects('decode')->with('foo.bar.baz')->returns($payload);
        $this->provider->expects('encode')->never();

        $this->createManager()->refresh('foo.bar.baz');
    }

    public static function missingIssuedAtProvider(): array
    {
        return [
            'absent' => [[]],
            'null' => [['iat' => null]],
        ];
    }

    public function testRefreshFailsWhenTheOldTokenCannotBeInvalidated(): void
    {
        $this->expectException(JwtException::class);
        $this->expectExceptionMessageIs('Unable to invalidate token because the blacklist write failed.');

        $payload = [
            'sub' => 1,
            'iat' => $this->testNowTimestamp,
            'jti' => 'foo',
        ];
        $refreshPayload = [
            'sub' => 1,
            'iat' => $this->testNowTimestamp,
            'jti' => 'bar',
        ];

        $this->config->allows('boolean')->with('jwt.blacklist_enabled')->returns(true);
        $this->config->allows('array')->with('jwt.validations')->returns([ValidationStub::class]);
        $this->config->allows('array')->with('jwt')->returns([]);
        $this->config->allows('get')->with('jwt.refresh_ttl')->returns(20160);
        $this->config->allows('get')->with('jwt.ttl')->returns(120);
        $this->config->allows('boolean')->with('jwt.refresh_iat')->returns(false);
        $this->config->allows('array')->with('jwt.persistent_claims')->returns([]);
        $this->claimFactory->expects('refresh')->returns($refreshPayload);
        $this->provider->expects('decode')->times(2)->with('foo.bar.baz')->returns($payload);
        $this->provider->expects('encode')->with($refreshPayload)->returns('baz.bar.foo');
        $this->blacklist->expects('has')->with($payload)->returns(false);
        $this->blacklist->expects('add')->with($payload)->returns(false);

        $this->createManager()->refresh('foo.bar.baz');
    }

    public function testInvalidateAnExpiredButStructurallyValidToken(): void
    {
        $token = 'foo.bar.baz';
        $payload = [
            'sub' => 1,
            'iss' => 'http://example.com',
            'exp' => $this->testNowTimestamp - 3600,
            'nbf' => $this->testNowTimestamp,
            'iat' => $this->testNowTimestamp,
            'jti' => 'foo',
        ];

        $this->config->allows('boolean')->with('jwt.blacklist_enabled')->returns(true);
        $this->config->expects('array')->with('jwt.validations')->never();
        $this->provider->expects('decode')->with('foo.bar.baz')->returns($payload);
        $this->blacklist->expects('add')->with($payload)->returns(true);

        $this->assertTrue($this->createManager()->invalidate($token));
    }

    public function testForceInvalidateATokenForever(): void
    {
        $token = 'foo.bar.baz';
        $payload = [
            'sub' => 1,
            'iss' => 'http://example.com',
            'exp' => $this->testNowTimestamp + 3600,
            'nbf' => $this->testNowTimestamp,
            'iat' => $this->testNowTimestamp,
            'jti' => 'foo',
        ];

        $this->config->allows('boolean')->with('jwt.blacklist_enabled')->returns(true);
        $this->provider->expects('decode')->with('foo.bar.baz')->returns($payload);
        $this->blacklist->expects('addForever')->with($payload)->returns(true);

        $this->assertTrue($this->createManager()->invalidate($token, true));
    }

    #[DataProvider('failedInvalidationProvider')]
    public function testInvalidateThrowsWhenBlacklistPersistenceFails(bool $forceForever, string $method): void
    {
        $this->expectException(JwtException::class);
        $this->expectExceptionMessageIs('Unable to invalidate token because the blacklist write failed.');

        $payload = [
            'sub' => 1,
            'iat' => $this->testNowTimestamp,
            'jti' => 'foo',
        ];

        $this->config->allows('boolean')->with('jwt.blacklist_enabled')->returns(true);
        $this->provider->expects('decode')->with('foo.bar.baz')->returns($payload);
        $this->blacklist->expects($method)->with($payload)->returns(false);

        $this->createManager()->invalidate('foo.bar.baz', $forceForever);
    }

    public static function failedInvalidationProvider(): array
    {
        return [
            'finite' => [false, 'add'],
            'forever' => [true, 'addForever'],
        ];
    }

    public function testFailedInvalidationKeepsTheTokenOutOfTheExceptionTrace(): void
    {
        $token = 'header.payload.signature';
        $payload = ['sub' => 1, 'iat' => $this->testNowTimestamp, 'jti' => 'foo'];
        $exception = null;

        $this->config->allows('boolean')->with('jwt.blacklist_enabled')->returns(true);
        $this->provider->expects('decode')->with($token)->returns($payload);
        $this->blacklist->expects('add')->with($payload)->returns(false);

        $manager = $this->createManager();
        $ignoreArguments = ini_set('zend.exception_ignore_args', '0');

        try {
            $manager->invalidate($token);
        } catch (JwtException $exception) {
        } finally {
            ini_set('zend.exception_ignore_args', $ignoreArguments);
        }

        $this->assertInstanceOf(JwtException::class, $exception);

        $frames = array_values(array_filter(
            $exception->getTrace(),
            static fn (array $frame): bool => ($frame['class'] ?? null) === JwtManager::class && $frame['function'] === 'invalidate',
        ));

        $this->assertCount(1, $frames);
        $this->assertInstanceOf(SensitiveParameterValue::class, $frames[0]['args'][0]);
        $this->assertSame($token, $frames[0]['args'][0]->getValue());
    }

    public function testInvalidateDoesNotReadTheBlacklistBeforeWriting(): void
    {
        $token = 'foo.bar.baz';
        $payload = [
            'sub' => 1,
            'iss' => 'http://example.com',
            'exp' => $this->testNowTimestamp + 3600,
            'nbf' => $this->testNowTimestamp,
            'iat' => $this->testNowTimestamp,
            'jti' => 'foo',
        ];

        $this->config->allows('boolean')->with('jwt.blacklist_enabled')->returns(true);
        $this->provider->expects('decode')->with('foo.bar.baz')->returns($payload);
        $this->blacklist->expects('has')->never();
        $this->blacklist->expects('add')->with($payload)->returns(true);

        $this->assertTrue($this->createManager()->invalidate($token));
    }

    public function testThrowAnExceptionWhenEnableBlacklistIsSetToFalse(): void
    {
        $this->expectException(JwtException::class);
        $this->expectExceptionMessageIs('You must have the blacklist enabled to invalidate a token.');

        $token = 'foo.bar.baz';

        $this->config->allows('boolean')->with('jwt.blacklist_enabled')->returns(false);

        $this->createManager()->invalidate($token);
    }

    public function testGetTheBlacklist(): void
    {
        $this->mockContainer();
        $this->mockConfig();
        $this->container->expects('make')->with(BlacklistContract::class)->returns($this->blacklist);

        $this->config->allows('boolean')->with('jwt.blacklist_enabled')->returns(false);

        $manager = $this->createManager();

        $this->assertSame($this->blacklist, $manager->blacklist());
        $this->assertSame($this->blacklist, $manager->blacklist());
    }

    private function setTestNow(): void
    {
        CarbonImmutable::setTestNow('2000-01-01T00:00:00.000000Z');

        $this->testNowTimestamp = Date::now()->timestamp;
    }

    private function mockContainer(): void
    {
        $this->container = Double::for(Container::class, override: true)->strict();

        $this->container->allows('make')
            ->with(Argument::type('string'), Argument::satisfies(static fn (array $parameters): bool => array_key_exists('config', $parameters)))
            ->resolves(static fn (string $class, array $parameters): object => new $class($parameters['config']));
    }

    private function mockConfig(): void
    {
        $this->config = Double::for(Repository::class)->strict();
        $this->config->allows('integer')->with('jwt.leeway')->returns(0);

        $this->container->allows('make')->with('config')->returns($this->config);
    }

    private function mockProvider(): void
    {
        $this->provider = Double::for(Lcobucci::class)->strict();
    }

    private function mockBlacklist(): void
    {
        $this->blacklist = Double::for(BlacklistContract::class)->strict();

        $this->container->allows('make')->with(BlacklistContract::class)->returns($this->blacklist);
    }

    private function mockClaimFactory(): void
    {
        $this->claimFactory = Double::for(ClaimFactory::class)->strict();
    }

    private function createManager(): JwtManager
    {
        $this->config->allows('string')->with('jwt.driver')->returns('dummy');

        $manager = new JwtManager($this->container->instance(), $this->claimFactory);
        $provider = $this->provider;

        $manager->extend('dummy', static fn () => $provider);

        return $manager;
    }

    private function mockUuid(string $value): void
    {
        Str::createUuidsUsing(fn () => Uuid::fromString($value));
    }
}

class JwtManagerTokenVersions
{
    /**
     * Create a new token version store.
     *
     * @param array<int, int> $current
     */
    public function __construct(
        public array $current = [],
    ) {
    }
}

class JwtManagerTokenVersionValidation implements ValidationContract
{
    /**
     * Create a new token version validation.
     */
    public function __construct(
        protected JwtManagerTokenVersions $versions,
    ) {
    }

    /**
     * Validate that the token carries the user's current token version.
     */
    public function validate(array $payload): void
    {
        if ($payload['ver'] !== $this->versions->current[$payload['sub']]) {
            throw new TokenInvalidException('Token version is outdated.');
        }
    }
}
