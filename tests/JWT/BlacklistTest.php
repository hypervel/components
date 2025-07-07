<?php

declare(strict_types=1);

namespace Hypervel\Tests\JWT;

use Carbon\Carbon;
use Hypervel\JWT\Blacklist;
use Hypervel\JWT\Contracts\StorageContract;
use Hypervel\JWT\Exceptions\TokenInvalidException;
use Hypervel\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 * @coversNothing
 */
class BlacklistTest extends TestCase
{
    /**
     * @var MockInterface|StorageContract
     */
    private StorageContract $storage;

    private Blacklist $blacklist;

    private int $testNowTimestamp;

    protected function setUp(): void
    {
        Carbon::setTestNow('2000-01-01T00:00:00.000000Z');

        $this->testNowTimestamp = Carbon::now()->timestamp;
        $this->storage = Mockery::mock(StorageContract::class);
        $this->blacklist = new Blacklist($this->storage);
    }

    public function testAddAValidTokenToTheBlacklist()
    {
        $payload = [
            'sub' => 1,
            'iss' => 'http://example.com',
            'exp' => $this->testNowTimestamp + 3600,
            'nbf' => $this->testNowTimestamp,
            'iat' => $this->testNowTimestamp,
            'jti' => 'foo',
        ];

        $refreshTTL = 20161;

        $this->storage->shouldReceive('get')
            ->with('foo')
            ->once()
            ->andReturn([]);

        $this->storage->shouldReceive('add')
            ->with('foo', ['valid_until' => $this->testNowTimestamp], $refreshTTL + 1)
            ->once();

        $this->blacklist->setRefreshTTL($refreshTTL)->add($payload);
    }

    public function testAddATokenWithNoExpToTheBlacklistForever()
    {
        $payload = [
            'sub' => 1,
            'iss' => 'http://example.com',
            'nbf' => $this->testNowTimestamp,
            'iat' => $this->testNowTimestamp,
            'jti' => 'foo',
        ];

        $this->storage->shouldReceive('forever')->with('foo', 'forever')->once();

        $this->blacklist->add($payload);
    }

    public function testReturnTrueWhenAddingAnExpiredTokenToTheBlacklist()
    {
        $payload = [
            'sub' => 1,
            'iss' => 'http://example.com',
            'exp' => $this->testNowTimestamp - 3600,
            'nbf' => $this->testNowTimestamp,
            'iat' => $this->testNowTimestamp,
            'jti' => 'foo',
        ];

        $refreshTTL = 20161;

        $this->storage->shouldReceive('get')
            ->with('foo')
            ->once()
            ->andReturn([]);

        $this->storage->shouldReceive('add')
            ->with('foo', ['valid_until' => $this->testNowTimestamp], $refreshTTL + 1)
            ->once();

        $this->assertTrue($this->blacklist->setRefreshTTL($refreshTTL)->add($payload));
    }

    public function testReturnTrueEarlyWhenAddingAnItemAndItAlreadyExists()
    {
        $payload = [
            'sub' => 1,
            'iss' => 'http://example.com',
            'exp' => $this->testNowTimestamp - 3600,
            'nbf' => $this->testNowTimestamp,
            'iat' => $this->testNowTimestamp,
            'jti' => 'foo',
        ];

        $refreshTTL = 20161;

        $this->storage->shouldReceive('get')
            ->with('foo')
            ->once()
            ->andReturn(['valid_until' => $this->testNowTimestamp]);

        $this->storage->shouldReceive('add')
            ->with('foo', ['valid_until' => $this->testNowTimestamp], $refreshTTL + 1)
            ->never();

        $this->assertTrue($this->blacklist->setRefreshTTL($refreshTTL)->add($payload));
    }

    public function testCheckWhetherATokenHasBeenBlacklisted()
    {
        $payload = [
            'sub' => 1,
            'iss' => 'http://example.com',
            'exp' => $this->testNowTimestamp + 3600,
            'nbf' => $this->testNowTimestamp,
            'iat' => $this->testNowTimestamp,
            'jti' => 'foobar',
        ];

        $this->storage->shouldReceive('get')->with('foobar')->once()->andReturn(['valid_until' => $this->testNowTimestamp]);

        $this->assertTrue($this->blacklist->has($payload));
    }

    #[DataProvider('blacklistProvider')]
    public function testCheckWhetherATokenHasNotBeenBlacklisted($result)
    {
        $payload = [
            'sub' => 1,
            'iss' => 'http://example.com',
            'exp' => $this->testNowTimestamp + 3600,
            'nbf' => $this->testNowTimestamp,
            'iat' => $this->testNowTimestamp,
            'jti' => 'foobar',
        ];

        $this->storage->shouldReceive('get')->with('foobar')->once()->andReturn($result);

        $this->assertFalse($this->blacklist->has($payload));
    }

    public static function blacklistProvider(): array
    {
        return [
            [null],
            [0],
            [''],
            [[]],
            [['valid_until' => strtotime('+1day')]],
        ];
    }

    public function testCheckWhetherATokenHasBeenBlacklistedForever()
    {
        $payload = [
            'sub' => 1,
            'iss' => 'http://example.com',
            'exp' => $this->testNowTimestamp + 3600,
            'nbf' => $this->testNowTimestamp,
            'iat' => $this->testNowTimestamp,
            'jti' => 'foobar',
        ];

        $this->storage->shouldReceive('get')->with('foobar')->once()->andReturn('forever');

        $this->assertTrue($this->blacklist->has($payload));
    }

    public function testCheckWhetherATokenHasBeenBlacklistedWhenTheTokenIsNotBlacklisted()
    {
        $payload = [
            'sub' => 1,
            'iss' => 'http://example.com',
            'exp' => $this->testNowTimestamp + 3600,
            'nbf' => $this->testNowTimestamp,
            'iat' => $this->testNowTimestamp,
            'jti' => 'foobar',
        ];

        $this->storage->shouldReceive('get')->with('foobar')->once()->andReturn(null);

        $this->assertFalse($this->blacklist->has($payload));
    }

    public function testRemoveATokenFromTheBlacklist()
    {
        $payload = [
            'sub' => 1,
            'iss' => 'http://example.com',
            'exp' => $this->testNowTimestamp + 3600,
            'nbf' => $this->testNowTimestamp,
            'iat' => $this->testNowTimestamp,
            'jti' => 'foobar',
        ];

        $this->storage->shouldReceive('destroy')->with('foobar')->andReturn(true);

        $this->assertTrue($this->blacklist->remove($payload));
    }

    public function testSetACustomUniqueKeyForTheBlacklist()
    {
        $payload = [
            'sub' => '1',
            'iss' => 'http://example.com',
            'exp' => $this->testNowTimestamp + 3600,
            'nbf' => $this->testNowTimestamp,
            'iat' => $this->testNowTimestamp,
            'jti' => 'foobar',
        ];

        $this->storage->shouldReceive('get')->with(1)->once()->andReturn(['valid_until' => $this->testNowTimestamp]);

        $this->assertTrue($this->blacklist->setKey('sub')->has($payload));
        $this->assertSame('1', $this->blacklist->getKey($payload));
    }

    public function testEmptyTheBlacklist()
    {
        $this->storage->shouldReceive('flush');

        $this->assertTrue($this->blacklist->clear());
    }

    public function testSetAndGetTheBlacklistGracePeriod()
    {
        $this->assertInstanceOf(Blacklist::class, $this->blacklist->setGracePeriod(15));

        $this->assertSame(15, $this->blacklist->getGracePeriod());
    }

    public function testSetAndGetTheBlacklistRefreshTTL()
    {
        $this->assertInstanceOf(Blacklist::class, $this->blacklist->setRefreshTTL(15));

        $this->assertSame(15, $this->blacklist->getRefreshTTL());
    }

    public function testKeyNotExistsInPayload()
    {
        $this->expectException(TokenInvalidException::class);
        $this->expectExceptionMessage('Claim `jti` is missing in payload for blacklist');

        $this->blacklist->getKey([]);
    }
}
