<?php

declare(strict_types=1);

namespace Hypervel\Tests\Jwt;

use Hypervel\Jwt\Blacklist;
use Hypervel\Jwt\Contracts\StorageContract;
use Hypervel\Jwt\Exceptions\TokenInvalidException;
use Hypervel\Jwt\Validations\ExpiredClaim;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Date;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;

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
        parent::setUp();

        CarbonImmutable::setTestNow('2000-01-01T00:00:00.000000Z');

        $this->testNowTimestamp = Date::now()->timestamp;
        $this->storage = m::mock(StorageContract::class);
        $this->blacklist = new Blacklist($this->storage);
    }

    public function testAddAValidTokenToTheBlacklist(): void
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
            ->once()
            ->andReturnTrue();

        $this->assertTrue($this->blacklist->setRefreshTTL($refreshTTL)->add($payload));
    }

    public function testAddATokenWithNoExpToTheBlacklistForever(): void
    {
        $payload = [
            'sub' => 1,
            'iss' => 'http://example.com',
            'nbf' => $this->testNowTimestamp,
            'iat' => $this->testNowTimestamp,
            'jti' => 'foo',
        ];

        $this->storage->shouldReceive('get')->with('foo')->once()->andReturnNull();
        $this->storage->shouldReceive('forever')
            ->with('foo', ['valid_until' => $this->testNowTimestamp])
            ->once()
            ->andReturnTrue();

        $this->assertTrue($this->blacklist->add($payload));
    }

    public function testAddATokenWithNullExpirationToTheBlacklistForever(): void
    {
        $payload = [
            'exp' => null,
            'iat' => $this->testNowTimestamp,
            'jti' => 'foo',
        ];

        $this->storage->shouldReceive('get')->with('foo')->once()->andReturnNull();
        $this->storage->shouldReceive('forever')
            ->with('foo', ['valid_until' => $this->testNowTimestamp])
            ->once()
            ->andReturnTrue();

        $this->assertTrue($this->blacklist->add($payload));
    }

    public function testAddARefreshableTokenForeverWhenTheRefreshWindowIsDisabled(): void
    {
        $payload = [
            'exp' => $this->testNowTimestamp + 3600,
            'iat' => $this->testNowTimestamp,
            'jti' => 'foo',
        ];

        $this->storage->shouldReceive('get')->with('foo')->once()->andReturnNull();
        $this->storage->shouldReceive('forever')
            ->with('foo', ['valid_until' => $this->testNowTimestamp])
            ->once()
            ->andReturnTrue();

        $this->assertTrue($this->blacklist->setRefreshTTL(null)->add($payload));
    }

    public function testPermanentBlacklistEntryHonorsGraceWhenRefreshWindowIsDisabled(): void
    {
        $payload = [
            'exp' => $this->testNowTimestamp + 3600,
            'iat' => $this->testNowTimestamp,
            'jti' => 'foo',
        ];
        $entry = ['valid_until' => $this->testNowTimestamp + 300];
        $blacklist = new Blacklist($this->storage, gracePeriod: 300, refreshTTL: null);

        $this->storage->shouldReceive('get')->with('foo')->times(3)->andReturn(null, $entry, $entry);
        $this->storage->shouldReceive('forever')->with('foo', $entry)->once()->andReturnTrue();

        $this->assertTrue($blacklist->add($payload));
        $this->assertFalse($blacklist->has($payload));

        CarbonImmutable::setTestNow(Date::now()->addSeconds(301));

        $this->assertTrue($blacklist->has($payload));
    }

    public function testPermanentBlacklistEntryDoesNotSlideGraceOnRepeatedAdd(): void
    {
        $payload = [
            'exp' => $this->testNowTimestamp + 3600,
            'iat' => $this->testNowTimestamp,
            'jti' => 'foo',
        ];
        $entry = ['valid_until' => $this->testNowTimestamp + 300];
        $blacklist = new Blacklist($this->storage, gracePeriod: 300, refreshTTL: null);

        $this->storage->shouldReceive('get')->with('foo')->once()->andReturn($entry);
        $this->storage->shouldReceive('forever')->never();

        $this->assertTrue($blacklist->add($payload));
    }

    public function testAddTokenToBlacklistForeverReturnsTheStorageResult(): void
    {
        $payload = ['jti' => 'foo'];

        $this->storage->shouldReceive('forever')->with('foo', 'forever')->once()->andReturnFalse();

        $this->assertFalse($this->blacklist->addForever($payload));
    }

    #[DataProvider('missingIssuedAtProvider')]
    public function testMissingIssuedAtUsesTheFiniteExpirationBoundary(array $issuedAt): void
    {
        $payload = [
            'exp' => $this->testNowTimestamp + 600,
            'jti' => 'foo',
            ...$issuedAt,
        ];
        $blacklist = new Blacklist($this->storage, refreshTTL: null, leeway: 60);

        $this->storage->shouldReceive('get')->with('foo')->once()->andReturnNull();
        $this->storage->shouldReceive('forever')->never();
        $this->storage->shouldReceive('add')
            ->with('foo', ['valid_until' => $this->testNowTimestamp], 12)
            ->once()
            ->andReturnTrue();

        $this->assertTrue($blacklist->add($payload));
    }

    public static function missingIssuedAtProvider(): array
    {
        return [
            'absent' => [[]],
            'null' => [['iat' => null]],
        ];
    }

    public function testReturnTrueWhenAddingAnExpiredTokenToTheBlacklist(): void
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
            ->once()
            ->andReturnTrue();

        $this->assertTrue($this->blacklist->setRefreshTTL($refreshTTL)->add($payload));
    }

    public function testReturnTrueEarlyWhenAddingAnItemAndItAlreadyExists(): void
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

    public function testBlacklistTtlRoundsFractionalMinutesUp(): void
    {
        CarbonImmutable::setTestNow('2000-01-01T00:00:00.500000Z');

        $nowTimestamp = Date::now()->timestamp;
        $payload = [
            'sub' => 1,
            'iss' => 'http://example.com',
            'exp' => $nowTimestamp + 60,
            'nbf' => $nowTimestamp,
            'iat' => $nowTimestamp,
            'jti' => 'foo',
        ];

        $this->storage->shouldReceive('get')
            ->with('foo')
            ->once()
            ->andReturn([]);

        $this->storage->shouldReceive('add')
            ->with('foo', ['valid_until' => $nowTimestamp], 2)
            ->once()
            ->andReturnTrue();

        $this->assertTrue($this->blacklist->setRefreshTTL(0)->add($payload));
    }

    public function testFiniteLifetimeStaysPositiveWhenTheClockAdvancesDuringTheStorageLookup(): void
    {
        $base = CarbonImmutable::parse('2000-01-01T00:00:00.000000Z');

        CarbonImmutable::setTestNow($base);

        $payload = [
            'exp' => $base->getTimestamp() - 59,
            'iat' => $base->getTimestamp() - 59,
            'jti' => 'foo',
        ];
        $blacklist = new Blacklist($this->storage, refreshTTL: 0);

        // Model the clock advancing while the tagged-cache lookup is in flight.
        $this->storage->shouldReceive('get')
            ->with('foo')
            ->once()
            ->andReturnUsing(function () use ($base) {
                CarbonImmutable::setTestNow($base->addSeconds(2));

                return null;
            });
        $this->storage->shouldReceive('add')
            ->with('foo', m::type('array'), m::on(fn (int $minutes): bool => $minutes >= 1))
            ->once()
            ->andReturnTrue();

        $this->assertTrue($blacklist->add($payload));
    }

    public function testExpirationLeewayCanDefineTheBlacklistLifetime(): void
    {
        $payload = [
            'exp' => $this->testNowTimestamp + 600,
            'iat' => $this->testNowTimestamp,
            'jti' => 'foo',
        ];
        $blacklist = new Blacklist($this->storage, refreshTTL: 5, leeway: 120);

        $this->storage->shouldReceive('get')->with('foo')->once()->andReturnNull();
        $this->storage->shouldReceive('add')
            ->with('foo', ['valid_until' => $this->testNowTimestamp], 13)
            ->once()
            ->andReturnTrue();

        $this->assertTrue($blacklist->add($payload));
    }

    public function testRefreshWindowCanDefineTheBlacklistLifetime(): void
    {
        $payload = [
            'exp' => $this->testNowTimestamp + 600,
            'iat' => $this->testNowTimestamp,
            'jti' => 'foo',
        ];
        $blacklist = new Blacklist($this->storage, refreshTTL: 20);

        $this->storage->shouldReceive('get')->with('foo')->once()->andReturnNull();
        $this->storage->shouldReceive('add')
            ->with('foo', ['valid_until' => $this->testNowTimestamp], 21)
            ->once()
            ->andReturnTrue();

        $this->assertTrue($blacklist->add($payload));
    }

    #[DataProvider('terminalExpirationProvider')]
    public function testTerminalTokensSkipBlacklistStorage(int $expirationOffset): void
    {
        $payload = [
            'exp' => $this->testNowTimestamp + $expirationOffset,
            'jti' => 'foo',
        ];
        $blacklist = new Blacklist($this->storage, refreshTTL: null, leeway: 60);

        $this->storage->shouldReceive('get', 'add', 'forever')->never();

        $this->assertTrue($blacklist->add($payload));
    }

    public static function terminalExpirationProvider(): array
    {
        return [
            'elapsed' => [-121],
            'exact boundary' => [-120],
        ];
    }

    public function testBlacklistEntryIsRetainedWhileExpirationLeewayStillAcceptsTheToken(): void
    {
        $payload = [
            'exp' => $this->testNowTimestamp - 30,
            'jti' => 'foo',
        ];
        $blacklist = new Blacklist($this->storage, refreshTTL: null, leeway: 60);

        // The same jwt.leeway feeds both owners: expiration is still accepted,
        // so the entry must be written.
        (new ExpiredClaim(['leeway' => 60]))->validate($payload);

        $this->storage->shouldReceive('get')
            ->with('foo')
            ->twice()
            ->andReturn(null, ['valid_until' => $this->testNowTimestamp]);
        $this->storage->shouldReceive('add')
            ->with('foo', ['valid_until' => $this->testNowTimestamp], 2)
            ->once()
            ->andReturnTrue();

        $this->assertTrue($blacklist->add($payload));
        $this->assertTrue($blacklist->has($payload));
    }

    public function testFiniteStorageFailureIsReturned(): void
    {
        $payload = [
            'exp' => $this->testNowTimestamp + 60,
            'iat' => $this->testNowTimestamp,
            'jti' => 'foo',
        ];

        $this->storage->shouldReceive('get')->with('foo')->once()->andReturnNull();
        $this->storage->shouldReceive('add')->with('foo', m::type('array'), 2)->once()->andReturnFalse();

        $this->assertFalse($this->blacklist->setRefreshTTL(0)->add($payload));
    }

    public function testPermanentStorageFailureIsReturned(): void
    {
        $payload = ['iat' => $this->testNowTimestamp, 'jti' => 'foo'];

        $this->storage->shouldReceive('get')->with('foo')->once()->andReturnNull();
        $this->storage->shouldReceive('forever')
            ->with('foo', ['valid_until' => $this->testNowTimestamp])
            ->once()
            ->andReturnFalse();

        $this->assertFalse($this->blacklist->add($payload));
    }

    public function testCheckWhetherATokenHasBeenBlacklisted(): void
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
    public function testCheckWhetherATokenHasNotBeenBlacklisted(mixed $result): void
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

    public function testCheckWhetherATokenHasBeenBlacklistedForever(): void
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

    public function testCheckWhetherATokenHasBeenBlacklistedWhenTheTokenIsNotBlacklisted(): void
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

    public function testRemoveATokenFromTheBlacklist(): void
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

    public function testSetACustomUniqueKeyForTheBlacklist(): void
    {
        $payload = [
            'sub' => '1',
            'iss' => 'http://example.com',
            'exp' => $this->testNowTimestamp + 3600,
            'nbf' => $this->testNowTimestamp,
            'iat' => $this->testNowTimestamp,
            'jti' => 'foobar',
        ];

        $this->storage->shouldReceive('get')->with('1')->once()->andReturn(['valid_until' => $this->testNowTimestamp]);

        $this->assertTrue($this->blacklist->setKey('sub')->has($payload));
        $this->assertSame('1', $this->blacklist->getKey($payload));
    }

    public function testEmptyTheBlacklistReturnsTheStorageResult(): void
    {
        $this->storage->shouldReceive('flush')->once()->andReturnFalse();

        $this->assertFalse($this->blacklist->clear());
    }

    public function testSetAndGetTheBlacklistGracePeriod(): void
    {
        $this->assertInstanceOf(Blacklist::class, $this->blacklist->setGracePeriod(15));

        $this->assertSame(15, $this->blacklist->getGracePeriod());
    }

    public function testSetAndGetTheBlacklistRefreshTTL(): void
    {
        $this->assertInstanceOf(Blacklist::class, $this->blacklist->setRefreshTTL(15));

        $this->assertSame(15, $this->blacklist->getRefreshTTL());

        $this->assertInstanceOf(Blacklist::class, $this->blacklist->setRefreshTTL(null));

        $this->assertNull($this->blacklist->getRefreshTTL());
    }

    public function testKeyNotExistsInPayload(): void
    {
        $this->expectException(TokenInvalidException::class);
        $this->expectExceptionMessage('Claim `jti` is missing or invalid in payload for blacklist');

        $this->blacklist->getKey([]);
    }

    public function testStringAndIntegerZeroAreValidBlacklistKeys(): void
    {
        $this->assertSame('0', $this->blacklist->getKey(['jti' => '0']));
        $this->assertSame('0', $this->blacklist->getKey(['jti' => 0]));
    }

    #[DataProvider('invalidBlacklistKeyProvider')]
    public function testInvalidBlacklistKeyShapeIsRejected(mixed $key): void
    {
        $this->expectException(TokenInvalidException::class);
        $this->expectExceptionMessage('Claim `jti` is missing or invalid in payload for blacklist');

        $this->blacklist->getKey(['jti' => $key]);
    }

    public static function invalidBlacklistKeyProvider(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
            'boolean' => [false],
            'float' => [1.5],
            'array' => [[]],
            'object' => [(object) ['id' => 'foo']],
        ];
    }
}
