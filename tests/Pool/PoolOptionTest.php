<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pool;

use Hypervel\Pool\PoolOption;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

class PoolOptionTest extends TestCase
{
    public function testMaxLifetimeDefaultsToDisabled(): void
    {
        $option = new PoolOption;

        $this->assertSame(-1.0, $option->getMaxLifetime());
    }

    public function testMaxLifetimeCanBeConfigured(): void
    {
        $option = new PoolOption(maxLifetime: 120.0);

        $this->assertSame(120.0, $option->getMaxLifetime());
    }

    public function testMaxLifetimeCanBeChanged(): void
    {
        $option = new PoolOption;

        $this->assertSame($option, $option->setMaxLifetime(30.0));
        $this->assertSame(30.0, $option->getMaxLifetime());
    }

    public function testJitteredLifetimeDeadlineDefaultsToDisabled(): void
    {
        $this->assertSame(0.0, PoolOption::jitteredLifetimeDeadline(100.0, -1.0));
    }

    public function testJitteredLifetimeDeadlineKeepsConfiguredLifetimeAsUpperBound(): void
    {
        $createdAt = 100.0;
        $maxLifetime = 60.0;

        $deadline = PoolOption::jitteredLifetimeDeadline($createdAt, $maxLifetime);

        $this->assertGreaterThanOrEqual(
            $createdAt + ($maxLifetime * PoolOption::MIN_LIFETIME_JITTER_BASIS / PoolOption::LIFETIME_JITTER_SCALE),
            $deadline
        );
        $this->assertLessThanOrEqual($createdAt + $maxLifetime, $deadline);
    }

    public function testValidConstructorAndSetterValuesAreAccepted(): void
    {
        $option = new PoolOption(
            minConnections: 0,
            maxConnections: 20,
            connectTimeout: 1.5,
            waitTimeout: 2.5,
            heartbeat: -1.0,
            heartbeatTimeout: 0.5,
            maxIdleTime: 30.0,
            maxLifetime: -1.0,
            events: ['borrowed', 'released'],
        );

        $this->assertSame(0, $option->getMinConnections());
        $this->assertSame(20, $option->getMaxConnections());
        $this->assertSame(['borrowed', 'released'], $option->getEvents());
        $this->assertSame($option, $option->setHeartbeat(5.0));
        $this->assertSame($option, $option->setMaxLifetime(60.0));
    }

    #[DataProvider('invalidConstructorOptionProvider')]
    public function testConstructorRejectsInvalidOptions(callable $construct, string $field): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("[{$field}]");

        $construct();
    }

    public static function invalidConstructorOptionProvider(): array
    {
        return [
            'negative minimum' => [static fn (): PoolOption => new PoolOption(minConnections: -1), 'min_connections'],
            'zero maximum' => [static fn (): PoolOption => new PoolOption(maxConnections: 0), 'max_connections'],
            'minimum exceeds maximum' => [
                static fn (): PoolOption => new PoolOption(minConnections: 2, maxConnections: 1),
                'min_connections',
            ],
            'zero connect timeout' => [static fn (): PoolOption => new PoolOption(connectTimeout: 0.0), 'connect_timeout'],
            'nan connect timeout' => [static fn (): PoolOption => new PoolOption(connectTimeout: NAN), 'connect_timeout'],
            'zero wait timeout' => [static fn (): PoolOption => new PoolOption(waitTimeout: 0.0), 'wait_timeout'],
            'infinite wait timeout' => [static fn (): PoolOption => new PoolOption(waitTimeout: INF), 'wait_timeout'],
            'zero heartbeat' => [static fn (): PoolOption => new PoolOption(heartbeat: 0.0), 'heartbeat'],
            'arbitrary negative heartbeat' => [static fn (): PoolOption => new PoolOption(heartbeat: -2.0), 'heartbeat'],
            'nan heartbeat' => [static fn (): PoolOption => new PoolOption(heartbeat: NAN), 'heartbeat'],
            'zero heartbeat timeout' => [
                static fn (): PoolOption => new PoolOption(heartbeatTimeout: 0.0),
                'heartbeat_timeout',
            ],
            'infinite heartbeat timeout' => [
                static fn (): PoolOption => new PoolOption(heartbeatTimeout: INF),
                'heartbeat_timeout',
            ],
            'zero maximum idle time' => [static fn (): PoolOption => new PoolOption(maxIdleTime: 0.0), 'max_idle_time'],
            'nan maximum idle time' => [static fn (): PoolOption => new PoolOption(maxIdleTime: NAN), 'max_idle_time'],
            'zero maximum lifetime' => [static fn (): PoolOption => new PoolOption(maxLifetime: 0.0), 'max_lifetime'],
            'arbitrary negative maximum lifetime' => [
                static fn (): PoolOption => new PoolOption(maxLifetime: -2.0),
                'max_lifetime',
            ],
            'infinite maximum lifetime' => [static fn (): PoolOption => new PoolOption(maxLifetime: INF), 'max_lifetime'],
            'associative events' => [static fn (): PoolOption => new PoolOption(events: ['event' => 'borrowed']), 'events'],
            'empty event' => [static fn (): PoolOption => new PoolOption(events: ['']), 'events'],
            'non-string event' => [static fn (): PoolOption => new PoolOption(events: [1]), 'events'],
        ];
    }

    #[DataProvider('invalidSetterProvider')]
    public function testSettersRejectInvalidOptions(callable $mutate, string $field): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("[{$field}]");

        $mutate(new PoolOption);
    }

    public static function invalidSetterProvider(): array
    {
        return [
            'negative minimum' => [static fn (PoolOption $option) => $option->setMinConnections(-1), 'min_connections'],
            'minimum exceeds maximum' => [static fn (PoolOption $option) => $option->setMinConnections(11), 'min_connections'],
            'zero maximum' => [static fn (PoolOption $option) => $option->setMaxConnections(0), 'max_connections'],
            'maximum below minimum' => [
                static fn (PoolOption $option) => $option->setMinConnections(5)->setMaxConnections(4),
                'min_connections',
            ],
            'connect timeout' => [static fn (PoolOption $option) => $option->setConnectTimeout(NAN), 'connect_timeout'],
            'wait timeout' => [static fn (PoolOption $option) => $option->setWaitTimeout(0.0), 'wait_timeout'],
            'heartbeat' => [static fn (PoolOption $option) => $option->setHeartbeat(0.0), 'heartbeat'],
            'heartbeat timeout' => [
                static fn (PoolOption $option) => $option->setHeartbeatTimeout(-1.0),
                'heartbeat_timeout',
            ],
            'maximum idle time' => [static fn (PoolOption $option) => $option->setMaxIdleTime(INF), 'max_idle_time'],
            'maximum lifetime' => [static fn (PoolOption $option) => $option->setMaxLifetime(-2.0), 'max_lifetime'],
            'events' => [static fn (PoolOption $option) => $option->setEvents(['']), 'events'],
        ];
    }

    public function testJitteredLifetimeDeadlineRejectsUndocumentedDisableValues(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('[max_lifetime]');

        PoolOption::jitteredLifetimeDeadline(100.0, 0.0);
    }
}
