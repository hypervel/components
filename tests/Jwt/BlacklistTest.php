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
use JMac\Testing\Double;
use JMac\Testing\DoubleInterface;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use PHPUnit\Framework\Attributes\DataProvider;

class BlacklistTest extends TestCase
{
    use VerifiesDoubles;

    private StorageContract&DoubleInterface $storage;

    private Blacklist $blacklist;

    private int $testNowTimestamp;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2000-01-01T00:00:00.000000Z');

        $this->testNowTimestamp = Date::now()->timestamp;
        $this->storage = Double::for(StorageContract::class)->strict();
        $this->blacklist = new Blacklist($this->storage, refreshTTL: 20160);
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

        $this->storage->expects('get')
            ->with('foo')
            ->returns([]);

        $this->storage->expects('add')
            ->with('foo', ['valid_until' => $this->testNowTimestamp], $refreshTTL + 1)
            ->returns(true);

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

        $this->storage->expects('get')->with('foo')->returns(null);
        $this->storage->expects('forever')
            ->with('foo', ['valid_until' => $this->testNowTimestamp])
            ->returns(true);

        $this->assertTrue($this->blacklist->add($payload));
    }

    public function testAddATokenWithNullExpirationToTheBlacklistForever(): void
    {
        $payload = [
            'exp' => null,
            'iat' => $this->testNowTimestamp,
            'jti' => 'foo',
        ];

        $this->storage->expects('get')->with('foo')->returns(null);
        $this->storage->expects('forever')
            ->with('foo', ['valid_until' => $this->testNowTimestamp])
            ->returns(true);

        $this->assertTrue($this->blacklist->add($payload));
    }

    public function testAddARefreshableTokenForeverWhenTheRefreshWindowIsDisabled(): void
    {
        $payload = [
            'exp' => $this->testNowTimestamp + 3600,
            'iat' => $this->testNowTimestamp,
            'jti' => 'foo',
        ];

        $this->storage->expects('get')->with('foo')->returns(null);
        $this->storage->expects('forever')
            ->with('foo', ['valid_until' => $this->testNowTimestamp])
            ->returns(true);

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

        $this->storage->expects('get')->with('foo')->times(3)->returns(null, $entry, $entry);
        $this->storage->expects('forever')->with('foo', $entry)->returns(true);

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

        $this->storage->expects('get')->with('foo')->returns($entry);
        $this->storage->expects('forever')->never();

        $this->assertTrue($blacklist->add($payload));
    }

    public function testAddTokenToBlacklistForeverReturnsTheStorageResult(): void
    {
        $payload = ['jti' => 'foo'];

        $this->storage->expects('forever')->with('foo', 'forever')->returns(false);

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

        $this->storage->expects('get')->with('foo')->returns(null);
        $this->storage->expects('forever')->never();
        $this->storage->expects('add')
            ->with('foo', ['valid_until' => $this->testNowTimestamp], 12)
            ->returns(true);

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

        $this->storage->expects('get')
            ->with('foo')
            ->returns([]);

        $this->storage->expects('add')
            ->with('foo', ['valid_until' => $this->testNowTimestamp], $refreshTTL + 1)
            ->returns(true);

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

        $this->storage->expects('get')
            ->with('foo')
            ->returns(['valid_until' => $this->testNowTimestamp]);

        $this->storage->expects('add')
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

        $this->storage->expects('get')
            ->with('foo')
            ->returns([]);

        $this->storage->expects('add')
            ->with('foo', ['valid_until' => $nowTimestamp], 2)
            ->returns(true);

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

        // Model the clock advancing while the storage lookup is in flight.
        $this->storage->expects('get')
            ->with('foo')
            ->resolves(function () use ($base) {
                CarbonImmutable::setTestNow($base->addSeconds(2));

                return null;
            });
        $this->storage->expects('add')
            ->with('foo', Argument::type('array'), Argument::satisfies(fn (int $minutes): bool => $minutes >= 1))
            ->returns(true);

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

        $this->storage->expects('get')->with('foo')->returns(null);
        $this->storage->expects('add')
            ->with('foo', ['valid_until' => $this->testNowTimestamp], 13)
            ->returns(true);

        $this->assertTrue($blacklist->add($payload));
    }

    #[DataProvider('refreshWindowLifetimeProvider')]
    public function testRefreshWindowCanDefineTheBlacklistLifetime(int $leeway, int $minutes): void
    {
        $payload = [
            'exp' => $this->testNowTimestamp + 600,
            'iat' => $this->testNowTimestamp,
            'jti' => 'foo',
        ];
        $blacklist = new Blacklist($this->storage, refreshTTL: 20, leeway: $leeway);

        $this->storage->expects('get')->with('foo')->returns(null);
        $this->storage->expects('add')
            ->with('foo', ['valid_until' => $this->testNowTimestamp], $minutes)
            ->returns(true);

        $this->assertTrue($blacklist->add($payload));
    }

    /**
     * Provide leeways and the blacklist lifetime the refresh window then requires.
     *
     * @return array<string, array{int, int}>
     */
    public static function refreshWindowLifetimeProvider(): array
    {
        return [
            'no leeway' => [0, 21],
            'leeway longer than the retention margin' => [120, 23],
        ];
    }

    #[DataProvider('terminalExpirationProvider')]
    public function testTerminalTokensSkipBlacklistStorage(int $expirationOffset): void
    {
        $payload = [
            'exp' => $this->testNowTimestamp + $expirationOffset,
            'jti' => 'foo',
        ];
        $blacklist = new Blacklist($this->storage, refreshTTL: null, leeway: 60);

        $this->storage->expects('get')->never();
        $this->storage->expects('add')->never();
        $this->storage->expects('forever')->never();

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

        $this->storage->expects('get')
            ->with('foo')
            ->times(2)
            ->returns(null, ['valid_until' => $this->testNowTimestamp]);
        $this->storage->expects('add')
            ->with('foo', ['valid_until' => $this->testNowTimestamp], 2)
            ->returns(true);

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

        $this->storage->expects('get')->with('foo')->returns(null);
        $this->storage->expects('add')->with('foo', Argument::type('array'), 2)->returns(false);

        $this->assertFalse($this->blacklist->setRefreshTTL(0)->add($payload));
    }

    public function testPermanentStorageFailureIsReturned(): void
    {
        $payload = ['iat' => $this->testNowTimestamp, 'jti' => 'foo'];

        $this->storage->expects('get')->with('foo')->returns(null);
        $this->storage->expects('forever')
            ->with('foo', ['valid_until' => $this->testNowTimestamp])
            ->returns(false);

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

        $this->storage->expects('get')->with('foobar')->returns(['valid_until' => $this->testNowTimestamp]);

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

        $this->storage->expects('get')->with('foobar')->returns($result);

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

        $this->storage->expects('get')->with('foobar')->returns('forever');

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

        $this->storage->expects('get')->with('foobar')->returns(null);

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

        $this->storage->allows('destroy')->with('foobar')->returns(true);

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

        $this->storage->expects('get')->with('1')->returns(['valid_until' => $this->testNowTimestamp]);

        $this->assertTrue($this->blacklist->setKey('sub')->has($payload));
        $this->assertSame('1', $this->blacklist->getKey($payload));
    }

    public function testEmptyTheBlacklistReturnsTheStorageResult(): void
    {
        $this->storage->expects('flush')->returns(false);

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
        $this->expectExceptionMessageIs('Claim `jti` is missing or invalid in payload for blacklist');

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
        $this->expectExceptionMessageIs('Claim `jti` is missing or invalid in payload for blacklist');

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
