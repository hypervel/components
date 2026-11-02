<?php

declare(strict_types=1);

namespace Hypervel\Tests\JWT;

use Carbon\Carbon;
use Hyperf\Config\Config;
use Hyperf\Contract\ConfigInterface;
use Hypervel\JWT\Contracts\BlacklistContract;
use Hypervel\JWT\Exceptions\TokenBlacklistedException;
use Hypervel\JWT\Exceptions\TokenExpiredException;
use Hypervel\JWT\Exceptions\TokenInvalidException;
use Hypervel\JWT\JWTManager;
use Hypervel\JWT\Providers\Lcobucci;
use Hypervel\JWT\Providers\Provider;
use Hypervel\JWT\Validations\ExpiredClaim;
use Hypervel\JWT\Validations\NotBeforeCliam;
use Hypervel\JWT\Validations\RequiredClaims;
use Hypervel\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;
use Psr\Container\ContainerInterface;

/**
 * @internal
 * @coversNothing
 */
class JWTManagerRefreshTest extends TestCase
{
    private const SECRET = 'jwt-refresh-test-secret-with-at-least-256-bits';

    /**
     * @var BlacklistContract|MockInterface
     */
    private BlacklistContract $blacklist;

    private int $now;

    protected function setUp(): void
    {
        Carbon::setTestNow('2000-01-01T00:00:00.000000Z');

        $this->now = Carbon::now()->timestamp;
        $this->blacklist = Mockery::mock(BlacklistContract::class);
    }

    public function testRefreshedTokenExpiresAfterTtlAndKeepsIssuedAt()
    {
        $manager = $this->createManager(['ttl' => 120]);

        $token = $this->createToken([
            'sub' => 1,
            'iat' => $iat = $this->now - 1800,
            'exp' => $this->now + 5400,
        ]);

        $payload = $manager->decode($manager->refresh($token), false);

        $this->assertSame($this->now + 120 * 60, $payload['exp']);
        $this->assertSame($iat, $payload['iat']);
        $this->assertSame('1', $payload['sub']);
    }

    public function testExpiredTokenCanBeRefreshedWithinRefreshWindow()
    {
        $manager = $this->createManager(['ttl' => 60, 'refresh_ttl' => 20160]);

        $token = $this->createToken([
            'sub' => 1,
            'iat' => $this->now - 7200,
            'exp' => $this->now - 3600,
        ]);

        try {
            $manager->decode($token);
            $this->fail('An expired token should not pass regular decoding.');
        } catch (TokenExpiredException) {
        }

        $payload = $manager->decode($manager->refresh($token));

        $this->assertSame($this->now + 3600, $payload['exp']);
    }

    public function testRefreshIsRejectedAfterRefreshWindowEvenWithLeeway()
    {
        $this->expectException(TokenExpiredException::class);
        $this->expectExceptionMessage('Token has expired and can no longer be refreshed');

        $manager = $this->createManager([
            'refresh_ttl' => 60,
            'leeway' => 30,
            'blacklist_enabled' => true,
        ]);

        $token = $this->createToken([
            'sub' => 1,
            'iat' => $this->now - 3601,
            'exp' => $this->now - 1,
            'jti' => 'foo',
        ]);

        $this->blacklist->shouldReceive('has')->andReturnFalse();
        $this->blacklist->shouldNotReceive('add');

        $manager->refresh($token);
    }

    public function testRefreshIsAllowedWhenRefreshWindowEndsNow()
    {
        $manager = $this->createManager(['ttl' => 60, 'refresh_ttl' => 60]);

        $token = $this->createToken([
            'sub' => 1,
            'iat' => $this->now - 3600,
            'exp' => $this->now,
        ]);

        $payload = $manager->decode($manager->refresh($token), false);

        $this->assertSame($this->now + 3600, $payload['exp']);
    }

    public function testRefreshDoesNotSetExpirationWhenTtlIsNull()
    {
        $manager = $this->createManager([
            'ttl' => null,
            'required_claims' => ['iat', 'sub'],
        ]);

        $token = $this->createToken([
            'sub' => 1,
            'iat' => $this->now - 60,
        ]);

        $payload = $manager->decode($manager->refresh($token), false);

        $this->assertArrayNotHasKey('exp', $payload);
    }

    public function testRefreshIsNotLimitedWhenRefreshTtlIsNull()
    {
        $manager = $this->createManager(['ttl' => 60, 'refresh_ttl' => null]);

        $token = $this->createToken([
            'sub' => 1,
            'iat' => $this->now - 10 * 365 * 24 * 3600,
            'exp' => $this->now - 3600,
        ]);

        $payload = $manager->decode($manager->refresh($token), false);

        $this->assertSame($this->now + 3600, $payload['exp']);
    }

    public function testRefreshStillRunsOtherValidations()
    {
        $this->expectException(TokenInvalidException::class);
        $this->expectExceptionMessage('Not Before (nbf) timestamp cannot be in the future');

        $manager = $this->createManager([
            'validations' => [RequiredClaims::class, ExpiredClaim::class, NotBeforeCliam::class],
        ]);

        $token = $this->createToken([
            'sub' => 1,
            'iat' => $this->now - 7200,
            'exp' => $this->now - 3600,
            'nbf' => $this->now + 3600,
        ]);

        $manager->refresh($token);
    }

    public function testRefreshStillVerifiesSignature()
    {
        $this->expectException(TokenInvalidException::class);

        $manager = $this->createManager();

        $token = $this->createToken([
            'sub' => 1,
            'iat' => $this->now - 7200,
            'exp' => $this->now - 3600,
        ], 'another-secret-with-at-least-256-bits-of-data');

        $manager->refresh($token);
    }

    public function testRefreshRequiresIssuedAt()
    {
        $this->expectException(TokenInvalidException::class);
        $this->expectExceptionMessage('Issued At (iat) claim is required to refresh a token.');

        $manager = $this->createManager(['validations' => []]);

        $token = $this->createToken([
            'sub' => 1,
            'exp' => $this->now + 3600,
        ]);

        $manager->refresh($token);
    }

    public function testBlacklistedTokenCannotBeRefreshed()
    {
        $this->expectException(TokenBlacklistedException::class);

        $manager = $this->createManager(['blacklist_enabled' => true]);

        $token = $this->createToken([
            'sub' => 1,
            'iat' => $this->now - 7200,
            'exp' => $this->now - 3600,
            'jti' => 'old-jti',
        ]);

        $this->blacklist->shouldReceive('has')->andReturnTrue();
        $this->blacklist->shouldNotReceive('add');

        $manager->refresh($token);
    }

    private function createManager(array $config = []): JWTManager
    {
        $config = new Config([
            'jwt' => array_merge([
                'secret' => self::SECRET,
                'algo' => Provider::ALGO_HS256,
                'keys' => [],
                'ttl' => 60,
                'refresh_ttl' => 20160,
                'validations' => [RequiredClaims::class, ExpiredClaim::class],
                'required_claims' => ['iat', 'exp', 'sub'],
                'persistent_claims' => [],
                'leeway' => 0,
                'blacklist_enabled' => false,
            ], $config),
        ]);

        /** @var ContainerInterface|MockInterface */
        $container = Mockery::mock(ContainerInterface::class);
        $container->shouldReceive('get')->with(ConfigInterface::class)->andReturn($config);
        $container->shouldReceive('get')->with(BlacklistContract::class)->andReturn($this->blacklist);

        return new JWTManager($container);
    }

    private function createToken(array $payload, string $secret = self::SECRET): string
    {
        return (new Lcobucci($secret, Provider::ALGO_HS256, []))->encode($payload);
    }
}
