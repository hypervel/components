<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Feature\Diagnostics;

use Hypervel\Database\Schema\Builder;
use Hypervel\Doctor\Diagnostics\SessionDriverIsReachable;
use Hypervel\Doctor\Results\Link;
use Hypervel\Support\Facades\File;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Doctor\Fixtures\RedisPingServer;
use Hypervel\Tests\Doctor\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;

class SessionDriverIsReachableTest extends TestCase
{
    use VerifiesDoubles;

    private string $path;

    /**
     * Create an isolated session directory.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->path = ParallelTesting::tempDir('DoctorSession');
        File::deleteDirectory($this->path);
        File::makeDirectory($this->path, 0775, true);
    }

    /**
     * Remove the session directory.
     */
    protected function tearDown(): void
    {
        try {
            File::deleteDirectory($this->path);
        } finally {
            parent::tearDown();
        }
    }

    #[DataProvider('fileDrivers')]
    public function testPassesWhenFileSessionsCanBeReached(string $driver): void
    {
        config([
            'session.driver' => $driver,
            'session.files' => $this->path,
        ]);

        $result = (new SessionDriverIsReachable)->check();

        $this->assertSame('pass', $result->status->value);
    }

    #[DataProvider('fileDrivers')]
    public function testLinksToSessionConfigurationWhenTheDriverCannotBeReached(string $driver): void
    {
        config([
            'session.driver' => $driver,
            'session.files' => $this->path . '/missing',
        ]);

        $result = (new SessionDriverIsReachable)->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertEquals([Link::docs('session', 'configuration')], $result->links);
    }

    /**
     * Provide file-backed session driver names.
     */
    public static function fileDrivers(): array
    {
        return [['file'], ['native']];
    }

    #[DataProvider('redisConnections')]
    public function testUsesTheEffectiveRedisSessionConnection(?string $connection, string $expected): void
    {
        config(['session.driver' => 'redis', 'session.connection' => $connection]);
        $result = RedisPingServer::check(new SessionDriverIsReachable, $expected, '+PONG');

        $this->assertSame('pass', $result->status->value, $result->details ?? '');
    }

    /**
     * Provide explicit and omitted Redis session connection choices.
     */
    public static function redisConnections(): array
    {
        return [[null, 'session'], ['', 'session'], ['custom', 'custom']];
    }

    public function testReportsRedisSessionsWhenTheServerRejectsThePing(): void
    {
        config(['session.driver' => 'redis', 'session.connection' => 'session']);
        $result = RedisPingServer::check(new SessionDriverIsReachable, 'session', '-ERR max number of clients reached');

        $this->assertSame('fail', $result->status->value);
        $this->assertSame('Redis connection [session] rejected PING: ERR max number of clients reached', $result->details);
    }

    public function testDatabaseSessionsKeepTheDefaultSchemaConnection(): void
    {
        config(['session.driver' => 'database', 'session.connection' => null, 'session.table' => 'custom_sessions']);
        $schema = Double::for(Builder::class)->strict();
        $schema->expects('hasTable')->with('custom_sessions')->returns(true);
        Schema::shouldReceive('connection')->with(null)->andReturn($schema);

        $this->assertSame('pass', (new SessionDriverIsReachable)->check()->status->value);
    }

    #[TestWith(['database'])]
    #[TestWith(['redis'])]
    public function testReportsMalformedSessionConnections(string $driver): void
    {
        config(['session.driver' => $driver, 'session.connection' => false]);

        $result = (new SessionDriverIsReachable)->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertSame('Configuration value for key [session.connection] must be a string or null, boolean given.', $result->details);
    }

    public function testDatabaseSessionsRequireAnExplicitTableSetting(): void
    {
        config(['session.driver' => 'database', 'session.table' => null]);

        $result = (new SessionDriverIsReachable)->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertSame('The database session table is not configured.', $result->details);
    }
}
