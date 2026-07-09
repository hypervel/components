<?php

declare(strict_types=1);

namespace Hypervel\Tests\Jwt;

use Carbon\Carbon;
use Hypervel\Config\Repository;
use Hypervel\Contracts\Container\Container;
use Hypervel\Jwt\ClaimFactory;
use Hypervel\Jwt\Contracts\BlacklistContract;
use Hypervel\Jwt\Exceptions\JwtException;
use Hypervel\Jwt\Exceptions\TokenBlacklistedException;
use Hypervel\Jwt\Exceptions\TokenExpiredException;
use Hypervel\Jwt\JwtManager;
use Hypervel\Jwt\Providers\Lcobucci;
use Hypervel\Jwt\Validations\ExpiredClaim;
use Hypervel\Jwt\Validations\NotBeforeClaim;
use Hypervel\Jwt\Validations\RequiredClaims;
use Hypervel\Support\Str;
use Hypervel\Tests\Jwt\Fixtures\ValidationStub;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Mockery\MockInterface;
use Symfony\Component\Uid\Uuid;

class JwtManagerTest extends TestCase
{
    /**
     * @var Container|MockInterface
     */
    private Container $container;

    /**
     * @var MockInterface|Repository
     */
    private Repository $config;

    /**
     * @var Lcobucci|MockInterface
     */
    private Lcobucci $provider;

    /**
     * @var BlacklistContract|MockInterface
     */
    private BlacklistContract $blacklist;

    /**
     * @var ClaimFactory|MockInterface
     */
    private ClaimFactory $claimFactory;

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

        $this->config->shouldReceive('boolean')->with('jwt.blacklist_enabled', false)->andReturnTrue();
        $this->provider->shouldReceive('encode')->with($payload)->andReturn($token);

        $this->assertEquals($token, $this->createManager()->encode($payload));
    }

    public function testEncodeAddsJtiWhenBlacklistIsEnabledAndMissing(): void
    {
        $token = 'foo.bar.baz';
        $payload = ['sub' => 1, 'iat' => $this->testNowTimestamp];
        $jti = '11111111-1111-4111-8111-111111111111';

        $this->mockUuid($jti);

        $this->config->shouldReceive('boolean')->with('jwt.blacklist_enabled', false)->andReturnTrue();
        $this->provider->shouldReceive('encode')->once()->with($payload + ['jti' => $jti])->andReturn($token);

        $this->assertSame($token, $this->createManager()->encode($payload));
    }

    public function testEncodeDoesNotAddJtiWhenBlacklistIsDisabled(): void
    {
        $token = 'foo.bar.baz';
        $payload = ['sub' => 1, 'iat' => $this->testNowTimestamp];

        $this->config->shouldReceive('boolean')->with('jwt.blacklist_enabled', false)->andReturnFalse();
        $this->provider->shouldReceive('encode')->once()->with($payload)->andReturn($token);

        $this->assertSame($token, $this->createManager()->encode($payload));
    }

    public function testConstructorDoesNotResolveBlacklistWhenBlacklistIsDisabled(): void
    {
        $container = m::mock(Container::class);
        $config = m::mock(Repository::class);

        $container->shouldReceive('make')->once()->with('config')->andReturn($config);
        $container->shouldReceive('make')->with(BlacklistContract::class)->never();
        $config->shouldReceive('boolean')->once()->with('jwt.blacklist_enabled', false)->andReturnFalse();

        $manager = new JwtManager($container, m::mock(ClaimFactory::class));

        $this->assertFalse($manager->hasBlacklistEnabled());
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

        $this->config->shouldReceive('boolean')->with('jwt.blacklist_enabled', false)->andReturnTrue();
        $this->config->shouldReceive('array')->with('jwt.validations', [])->andReturn([ValidationStub::class]);
        $this->config->shouldReceive('array')->with('jwt')->andReturn([]);
        $this->provider->shouldReceive('decode')->with($token)->andReturn($payload);
        $this->blacklist->shouldReceive('has')->with($payload)->andReturn(false);

        $this->assertSame($payload, $this->createManager()->decode($token));
    }

    public function testThrowExceptionWhenTokenIsBlacklisted(): void
    {
        $this->expectException(TokenBlacklistedException::class);
        $this->expectExceptionMessage('The token has been blacklisted');

        $token = 'foo.bar.baz';
        $payload = [
            'sub' => 1,
            'iss' => 'http://example.com',
            'exp' => $this->testNowTimestamp + 3600,
            'nbf' => $this->testNowTimestamp,
            'iat' => $this->testNowTimestamp,
            'jti' => 'foo',
        ];

        $this->config->shouldReceive('boolean')->with('jwt.blacklist_enabled', false)->andReturnTrue();
        $this->config->shouldReceive('array')->with('jwt.validations', [])->andReturn([ValidationStub::class]);
        $this->config->shouldReceive('array')->with('jwt')->andReturn([]);
        $this->provider->shouldReceive('decode')->once()->with($token)->andReturn($payload);
        $this->blacklist->shouldReceive('has')->with($payload)->andReturn(true);

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

        $this->config->shouldReceive('boolean')->with('jwt.blacklist_enabled', false)->andReturnTrue();
        $this->config->shouldReceive('array')->with('jwt.validations', [])->andReturn([ValidationStub::class]);
        $this->config->shouldReceive('array')->with('jwt')->andReturn([]);
        $this->config->shouldReceive('get')->with('jwt.refresh_ttl', 20160)->andReturn(20160);
        $this->config->shouldReceive('array')->with('jwt.persistent_claims', [])->andReturn(['iss']);
        $this->config->shouldReceive('get')->with('jwt.ttl', 120)->andReturn(120);
        $this->config->shouldReceive('boolean')->with('jwt.refresh_iat', false)->andReturnFalse();
        $this->claimFactory->shouldReceive('refresh')->once()->with(
            $payload,
            120,
            false,
            false,
            ['iss'],
            [],
        )->andReturn($refreshPayload);
        $this->provider->shouldReceive('decode')->twice()->with('foo.bar.baz')->andReturn($payload);
        $this->provider->shouldReceive('encode')->with($refreshPayload)->andReturn($refreshedToken);
        $this->blacklist->shouldReceive('has')->with($payload)->andReturn(false);
        $this->blacklist->shouldReceive('add')->once()->with($payload);

        $this->assertSame($refreshedToken, $this->createManager()->refresh($token));
    }

    public function testRefreshDoesNotInvalidateOldTokenWhenEncodingReplacementFails(): void
    {
        $this->expectException(JwtException::class);
        $this->expectExceptionMessage('signing failed');

        $token = 'foo.bar.baz';
        $payload = [
            'sub' => 1,
            'iat' => $this->testNowTimestamp,
        ];
        $refreshPayload = [
            'sub' => 1,
            'iat' => $this->testNowTimestamp,
        ];

        $this->config->shouldReceive('boolean')->with('jwt.blacklist_enabled', false)->andReturnTrue();
        $this->config->shouldReceive('array')->with('jwt.validations', [])->andReturn([RequiredClaims::class]);
        $this->config->shouldReceive('array')->with('jwt')->andReturn(['required_claims' => ['iat', 'sub']]);
        $this->config->shouldReceive('get')->with('jwt.refresh_ttl', 20160)->andReturn(20160);
        $this->config->shouldReceive('array')->with('jwt.persistent_claims', [])->andReturn([]);
        $this->config->shouldReceive('get')->with('jwt.ttl', 120)->andReturn(120);
        $this->config->shouldReceive('boolean')->with('jwt.refresh_iat', false)->andReturnFalse();
        $this->claimFactory->shouldReceive('refresh')->once()->andReturn($refreshPayload);
        $this->provider->shouldReceive('decode')->once()->with($token)->andReturn($payload);
        $this->provider->shouldReceive('encode')->once()->with($refreshPayload + ['jti' => '11111111-1111-4111-8111-111111111111'])->andThrow(new JwtException('signing failed'));
        $this->blacklist->shouldReceive('has')->once()->with($payload)->andReturnFalse();
        $this->blacklist->shouldReceive('add')->never();

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

        $this->config->shouldReceive('boolean')->with('jwt.blacklist_enabled', false)->andReturnFalse();
        $this->config->shouldReceive('array')->with('jwt.validations', [])->andReturn([ValidationStub::class]);
        $this->config->shouldReceive('array')->with('jwt')->andReturn([]);
        $this->config->shouldReceive('get')->with('jwt.refresh_ttl', 20160)->andReturn(20160);
        $this->config->shouldReceive('array')->with('jwt.persistent_claims', [])->andReturn(['iss']);
        $this->config->shouldReceive('get')->with('jwt.ttl', 120)->andReturn(null);
        $this->config->shouldReceive('boolean')->with('jwt.refresh_iat', false)->andReturnFalse();
        $this->claimFactory->shouldReceive('refresh')->once()->with(
            $payload,
            null,
            false,
            false,
            ['iss'],
            [],
        )->andReturn($refreshPayload);
        $this->provider->shouldReceive('decode')->once()->with('foo.bar.baz')->andReturn($payload);
        $this->provider->shouldReceive('encode')->with($refreshPayload)->andReturn($refreshedToken);

        $this->assertSame($refreshedToken, $this->createManager()->refresh($token));
    }

    public function testDecodeStillRejectsExpiredTokensWhenExpiredClaimValidationIsEnabled(): void
    {
        $this->expectException(TokenExpiredException::class);
        $this->expectExceptionMessage('Token has expired');

        $payload = [
            'sub' => 1,
            'exp' => $this->testNowTimestamp - 3600,
            'iat' => $this->testNowTimestamp,
        ];

        $this->config->shouldReceive('boolean')->with('jwt.blacklist_enabled', false)->andReturnFalse();
        $this->config->shouldReceive('array')->with('jwt.validations', [])->andReturn([RequiredClaims::class, ExpiredClaim::class]);
        $this->config->shouldReceive('array')->with('jwt')->andReturn(['required_claims' => ['iat', 'sub']]);
        $this->provider->shouldReceive('decode')->once()->with('foo.bar.baz')->andReturn($payload);

        $this->createManager()->decode('foo.bar.baz');
    }

    public function testRefreshSkipsTemporalValidationsInsideRefreshWindow(): void
    {
        $token = 'foo.bar.baz';
        $refreshedToken = 'baz.bar.foo';
        $payload = [
            'sub' => 1,
            'exp' => $this->testNowTimestamp - 3600,
            'iat' => $this->testNowTimestamp,
        ];
        $refreshPayload = [
            'sub' => 1,
            'iat' => $this->testNowTimestamp,
            'exp' => $this->testNowTimestamp + 7200,
        ];

        $this->config->shouldReceive('boolean')->with('jwt.blacklist_enabled', false)->andReturnFalse();
        $this->config->shouldReceive('array')->with('jwt.validations', [])->andReturn([RequiredClaims::class, ExpiredClaim::class]);
        $this->config->shouldReceive('array')->with('jwt')->andReturn(['required_claims' => ['iat', 'sub']]);
        $this->config->shouldReceive('get')->with('jwt.refresh_ttl', 20160)->andReturn(20160);
        $this->config->shouldReceive('get')->with('jwt.ttl', 120)->andReturn(120);
        $this->config->shouldReceive('boolean')->with('jwt.refresh_iat', false)->andReturnFalse();
        $this->config->shouldReceive('array')->with('jwt.persistent_claims', [])->andReturn([]);
        $this->claimFactory->shouldReceive('refresh')->once()->with(
            $payload,
            120,
            false,
            false,
            [],
            [],
        )->andReturn($refreshPayload);
        $this->provider->shouldReceive('decode')->once()->with($token)->andReturn($payload);
        $this->provider->shouldReceive('encode')->once()->with($refreshPayload)->andReturn($refreshedToken);

        $this->assertSame($refreshedToken, $this->createManager()->refresh($token));
    }

    public function testRefreshRejectsFutureNotBeforeClaim(): void
    {
        $this->expectException(JwtException::class);
        $this->expectExceptionMessage('Not Before (nbf) timestamp cannot be in the future');

        $token = 'foo.bar.baz';
        $payload = [
            'sub' => 1,
            'iat' => $this->testNowTimestamp,
            'nbf' => $this->testNowTimestamp + 3600,
        ];

        $this->config->shouldReceive('boolean')->with('jwt.blacklist_enabled', false)->andReturnFalse();
        $this->config->shouldReceive('array')->with('jwt.validations', [])->andReturn([RequiredClaims::class, NotBeforeClaim::class]);
        $this->config->shouldReceive('array')->with('jwt')->andReturn(['required_claims' => ['iat', 'sub'], 'leeway' => 0]);
        $this->provider->shouldReceive('decode')->once()->with($token)->andReturn($payload);
        $this->provider->shouldReceive('encode')->never();

        $this->createManager()->refresh($token);
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

        $this->config->shouldReceive('boolean')->with('jwt.blacklist_enabled', false)->andReturnFalse();
        $this->config->shouldReceive('array')->with('jwt.validations', [])->andReturn([RequiredClaims::class, NotBeforeClaim::class]);
        $this->config->shouldReceive('array')->with('jwt')->andReturn(['required_claims' => ['iat', 'sub'], 'leeway' => 0]);
        $this->config->shouldReceive('get')->with('jwt.refresh_ttl', 20160)->andReturn(20160);
        $this->config->shouldReceive('get')->with('jwt.ttl', 120)->andReturn(120);
        $this->config->shouldReceive('boolean')->with('jwt.refresh_iat', false)->andReturnFalse();
        $this->config->shouldReceive('array')->with('jwt.persistent_claims', [])->andReturn([]);
        $this->claimFactory->shouldReceive('refresh')->once()->with(
            $payload,
            120,
            false,
            false,
            [],
            [],
        )->andReturn($refreshPayload);
        $this->provider->shouldReceive('decode')->once()->with($token)->andReturn($payload);
        $this->provider->shouldReceive('encode')->once()->with($refreshPayload)->andReturn($refreshedToken);

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

        $this->config->shouldReceive('boolean')->with('jwt.blacklist_enabled', false)->andReturnFalse();
        $this->config->shouldReceive('array')->with('jwt.validations', [])->andReturn([RequiredClaims::class]);
        $this->config->shouldReceive('array')->with('jwt')->andReturn(['required_claims' => ['iat', 'sub']]);
        $this->config->shouldReceive('get')->with('jwt.refresh_ttl', 20160)->andReturn(20160);
        $this->config->shouldReceive('boolean')->with('jwt.refresh_iat', false)->andReturnTrue();
        $this->config->shouldReceive('array')->with('jwt.persistent_claims', [])->andReturn(['tenant']);
        $this->claimFactory->shouldReceive('refresh')->once()->with(
            $payload,
            null,
            true,
            true,
            ['tenant'],
            ['tenant' => 'acme'],
        )->andReturn($refreshPayload);
        $this->provider->shouldReceive('decode')->once()->with($token)->andReturn($payload);
        $this->provider->shouldReceive('encode')->once()->with($refreshPayload)->andReturn($refreshedToken);

        $this->assertSame($refreshedToken, $this->createManager()->refresh(
            token: $token,
            resetClaims: true,
            customClaims: ['tenant' => 'acme'],
            ttl: null,
        ));
    }

    public function testRefreshThrowsWhenRefreshWindowHasExpired(): void
    {
        $this->expectException(TokenExpiredException::class);
        $this->expectExceptionMessage('Token has expired and can no longer be refreshed');

        $token = 'foo.bar.baz';
        $payload = [
            'sub' => 1,
            'iss' => 'http://example.com',
            'exp' => $this->testNowTimestamp - 3600,
            'nbf' => $this->testNowTimestamp,
            'iat' => $this->testNowTimestamp - 660,
            'jti' => 'foo',
        ];

        $this->config->shouldReceive('boolean')->with('jwt.blacklist_enabled', false)->andReturnTrue();
        $this->config->shouldReceive('array')->with('jwt.validations', [])->andReturn([ValidationStub::class]);
        $this->config->shouldReceive('array')->with('jwt')->andReturn([]);
        $this->config->shouldReceive('get')->with('jwt.refresh_ttl', 20160)->andReturn(10);
        $this->provider->shouldReceive('decode')->once()->with('foo.bar.baz')->andReturn($payload);
        $this->provider->shouldReceive('encode')->never();
        $this->blacklist->shouldReceive('has')->with($payload)->andReturn(false);
        $this->blacklist->shouldReceive('add')->never();

        $this->createManager()->refresh($token);
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

        $this->config->shouldReceive('boolean')->with('jwt.blacklist_enabled', false)->andReturnFalse();
        $this->config->shouldReceive('array')->with('jwt.validations', [])->andReturn([ValidationStub::class]);
        $this->config->shouldReceive('array')->with('jwt')->andReturn([]);
        $this->config->shouldReceive('get')->with('jwt.refresh_ttl', 20160)->andReturn(null);
        $this->config->shouldReceive('array')->with('jwt.persistent_claims', [])->andReturn(['iss']);
        $this->config->shouldReceive('get')->with('jwt.ttl', 120)->andReturn(120);
        $this->config->shouldReceive('boolean')->with('jwt.refresh_iat', false)->andReturnFalse();
        $this->claimFactory->shouldReceive('refresh')->once()->with(
            $payload,
            120,
            false,
            false,
            ['iss'],
            [],
        )->andReturn($refreshPayload);
        $this->provider->shouldReceive('decode')->once()->with('foo.bar.baz')->andReturn($payload);
        $this->provider->shouldReceive('encode')->with($refreshPayload)->andReturn($refreshedToken);

        $this->assertSame($refreshedToken, $this->createManager()->refresh($token));
    }

    public function testInvalidateAToken(): void
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

        $this->config->shouldReceive('boolean')->with('jwt.blacklist_enabled', false)->andReturnTrue();
        $this->provider->shouldReceive('decode')->once()->with('foo.bar.baz')->andReturn($payload);
        $this->blacklist->shouldReceive('has')->with($payload)->andReturn(false);
        $this->blacklist->shouldReceive('add')->with($payload)->andReturn(true);

        $this->createManager()->invalidate($token);
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

        $this->config->shouldReceive('boolean')->with('jwt.blacklist_enabled', false)->andReturnTrue();
        $this->provider->shouldReceive('decode')->once()->with('foo.bar.baz')->andReturn($payload);
        $this->blacklist->shouldReceive('has')->with($payload)->andReturn(false);
        $this->blacklist->shouldReceive('addForever')->with($payload)->andReturn(true);

        $this->createManager()->invalidate($token, true);
    }

    public function testInvalidateIsIdempotentForAlreadyBlacklistedTokens(): void
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

        $this->config->shouldReceive('boolean')->with('jwt.blacklist_enabled', false)->andReturnTrue();
        $this->provider->shouldReceive('decode')->once()->with('foo.bar.baz')->andReturn($payload);
        $this->blacklist->shouldNotReceive('has');
        $this->blacklist->shouldReceive('add')->once()->with($payload)->andReturn(true);

        $this->assertTrue($this->createManager()->invalidate($token));
    }

    public function testThrowAnExceptionWhenEnableBlacklistIsSetToFalse(): void
    {
        $this->expectException(JwtException::class);
        $this->expectExceptionMessage('You must have the blacklist enabled to invalidate a token.');

        $token = 'foo.bar.baz';

        $this->config->shouldReceive('boolean')->with('jwt.blacklist_enabled', false)->andReturnFalse();

        $this->createManager()->invalidate($token);
    }

    private function setTestNow(): void
    {
        Carbon::setTestNow('2000-01-01T00:00:00.000000Z');

        $this->testNowTimestamp = Carbon::now()->timestamp;
    }

    private function mockContainer(): void
    {
        $this->container = m::mock(Container::class);
    }

    private function mockConfig(): void
    {
        $this->config = m::mock(Repository::class);

        $this->container->shouldReceive('make')->with('config')->andReturn($this->config);
    }

    private function mockProvider(): void
    {
        $this->provider = m::mock(Lcobucci::class);
    }

    private function mockBlacklist(): void
    {
        $this->blacklist = m::mock(BlacklistContract::class);

        $this->container->shouldReceive('make')->with(BlacklistContract::class)->andReturn($this->blacklist);
    }

    private function mockClaimFactory(): void
    {
        $this->claimFactory = m::mock(ClaimFactory::class);
    }

    private function createManager(): JwtManager
    {
        $this->config->shouldReceive('string')->with('jwt.driver', 'lcobucci')->andReturn('dummy');

        $manager = new JwtManager($this->container, $this->claimFactory);

        $manager->extend('dummy', fn () => $this->provider);

        return $manager;
    }

    private function mockUuid(string $value): void
    {
        Str::createUuidsUsing(fn () => Uuid::fromString($value));
    }
}
