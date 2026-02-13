<?php

declare(strict_types=1);

namespace Hypervel\Tests\JWT;

use Carbon\Carbon;
use Hypervel\Config\Repository;
use Hypervel\Contracts\Container\Container;
use Hypervel\JWT\Contracts\BlacklistContract;
use Hypervel\JWT\Exceptions\JWTException;
use Hypervel\JWT\Exceptions\TokenBlacklistedException;
use Hypervel\JWT\JWTManager;
use Hypervel\JWT\Providers\Lcobucci;
use Hypervel\Tests\JWT\Stub\ValidationStub;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Mockery\MockInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidFactoryInterface;
use Ramsey\Uuid\UuidInterface;

/**
 * @internal
 * @coversNothing
 */
class JWTManagerTest extends TestCase
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

    private int $testNowTimestamp;

    private UuidFactoryInterface $originalUuidFactory;

    protected function setUp(): void
    {
        $this->setTestNow();
        $this->mockContainer();
        $this->mockConfig();
        $this->mockProvider();
        $this->mockBlacklist();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (isset($this->originalUuidFactory)) {
            Uuid::setFactory($this->originalUuidFactory);
        }
    }

    public function testEncodeAPayload()
    {
        $jti = 'foo';
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

        $this->config->shouldReceive('get')->with('jwt.blacklist_enabled', false)->andReturnTrue();
        $this->provider->shouldReceive('encode')->with($payload)->andReturn($token);

        $this->assertEquals($token, $this->createManager()->encode($payload));
    }

    public function testDecodeAToken()
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

        $this->config->shouldReceive('get')->with('jwt.blacklist_enabled', false)->andReturnTrue();
        $this->config->shouldReceive('get')->with('jwt.validations', [])->andReturn([ValidationStub::class]);
        $this->config->shouldReceive('get')->with('jwt')->andReturn([]);
        $this->provider->shouldReceive('decode')->with($token)->andReturn($payload);
        $this->blacklist->shouldReceive('has')->with($payload)->andReturn(false);

        $this->assertSame($payload, $this->createManager()->decode($token));
    }

    public function testThrowExceptionWhenTokenIsBlacklisted()
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

        $this->config->shouldReceive('get')->with('jwt.blacklist_enabled', false)->andReturnTrue();
        $this->config->shouldReceive('get')->with('jwt.validations', [])->andReturn([ValidationStub::class]);
        $this->config->shouldReceive('get')->with('jwt')->andReturn([]);
        $this->provider->shouldReceive('decode')->once()->with($token)->andReturn($payload);
        $this->blacklist->shouldReceive('has')->with($payload)->andReturn(true);

        $this->createManager()->decode($token);
    }

    public function testRefreshAToken()
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
        $refreshJti = 'bar';
        $refreshPayload = [
            'sub' => 1,
            'iss' => 'http://example.com',
            'iat' => $this->testNowTimestamp,
            'jti' => $refreshJti,
        ];

        $this->mockUuid($refreshJti);

        $this->config->shouldReceive('get')->with('jwt.blacklist_enabled', false)->andReturnTrue();
        $this->config->shouldReceive('get')->with('jwt.validations', [])->andReturn([ValidationStub::class]);
        $this->config->shouldReceive('get')->with('jwt')->andReturn([]);
        $this->config->shouldReceive('get')->with('jwt.persistent_claims', [])->andReturn(['iss']);
        $this->provider->shouldReceive('decode')->twice()->with('foo.bar.baz')->andReturn($payload);
        $this->provider->shouldReceive('encode')->with($refreshPayload)->andReturn($refreshedToken);
        $this->blacklist->shouldReceive('has')->with($payload)->andReturn(false);
        $this->blacklist->shouldReceive('add')->once()->with($payload);

        $this->assertSame($refreshedToken, $this->createManager()->refresh($token));
    }

    public function testInvalidateAToken()
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

        $this->config->shouldReceive('get')->with('jwt.blacklist_enabled', false)->andReturnTrue();
        $this->provider->shouldReceive('decode')->once()->with('foo.bar.baz')->andReturn($payload);
        $this->blacklist->shouldReceive('has')->with($payload)->andReturn(false);
        $this->blacklist->shouldReceive('add')->with($payload)->andReturn(true);

        $this->createManager()->invalidate($token);
    }

    public function testForceInvalidateATokenForever()
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

        $this->config->shouldReceive('get')->with('jwt.blacklist_enabled', false)->andReturnTrue();
        $this->provider->shouldReceive('decode')->once()->with('foo.bar.baz')->andReturn($payload);
        $this->blacklist->shouldReceive('has')->with($payload)->andReturn(false);
        $this->blacklist->shouldReceive('addForever')->with($payload)->andReturn(true);

        $this->createManager()->invalidate($token, true);
    }

    public function testThrowAnExceptionWhenEnableBlacklistIsSetToFalse()
    {
        $this->expectException(JWTException::class);
        $this->expectExceptionMessage('You must have the blacklist enabled to invalidate a token.');

        $token = 'foo.bar.baz';

        $this->config->shouldReceive('get')->with('jwt.blacklist_enabled', false)->andReturnFalse();

        $this->createManager()->invalidate($token);
    }

    private function setTestNow()
    {
        Carbon::setTestNow('2000-01-01T00:00:00.000000Z');

        $this->testNowTimestamp = Carbon::now()->timestamp;
    }

    private function mockContainer()
    {
        $this->container = m::mock(Container::class);
    }

    private function mockConfig()
    {
        $this->config = m::mock(Repository::class);

        $this->container->shouldReceive('get')->with('config')->andReturn($this->config);
    }

    private function mockProvider()
    {
        $this->provider = m::mock(Lcobucci::class);
    }

    private function mockBlacklist()
    {
        $this->blacklist = m::mock(BlacklistContract::class);

        $this->container->shouldReceive('make')->with(BlacklistContract::class)->andReturn($this->blacklist);
    }

    private function createManager(): JWTManager
    {
        $this->config->shouldReceive('get')->with('jwt.driver', 'lcobucci')->andReturn('dummy');

        $manager = new JWTManager($this->container);

        $manager->extend('dummy', fn () => $this->provider);

        return $manager;
    }

    private function mockUuid(string $value)
    {
        if (! isset($this->originalUuidFactory)) {
            $this->originalUuidFactory = Uuid::getFactory();
        }

        /** @var MockInterface|UuidFactoryInterface */
        $factory = m::mock(UuidFactoryInterface::class);

        // Ignore Serializable interface deprecation warnings in PHP 8.1+
        /** @var MockInterface|UuidInterface */
        $uuid = $this->runInSpecifyErrorReportingLevel(
            E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED,
            fn () => m::mock(UuidInterface::class)
        );

        $uuid->shouldReceive('__toString')->andReturn($value);

        $factory->shouldReceive('uuid4')->andReturn($uuid);

        Uuid::setFactory($factory);
    }

    private function runInSpecifyErrorReportingLevel(int $level, callable $callback)
    {
        $originalLevel = error_reporting($level);

        $result = $callback();

        error_reporting($originalLevel);

        return $result;
    }
}
