<?php

declare(strict_types=1);

namespace Hypervel\Tests\ConnectionPool;

use Error;
use Hypervel\ConnectionPool\Events\ConnectionReleasing;
use Hypervel\ConnectionPool\PoolOptions;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

class PoolOptionsTest extends TestCase
{
    public function testDefaults(): void
    {
        $options = PoolOptions::fromArray([]);

        $this->assertSame(1, $options->minRetainedConnections);
        $this->assertSame(10, $options->maxConnections);
        $this->assertSame(10.0, $options->connectTimeout);
        $this->assertSame(3.0, $options->waitTimeout);
        $this->assertSame(1.0, $options->heartbeatTimeout);
        $this->assertSame(60.0, $options->maxIdleTime);
        $this->assertNull($options->heartbeatInterval);
        $this->assertNull($options->idleCheckInterval);
        $this->assertNull($options->maxLifetime);
        $this->assertSame([], $options->events);
    }

    public function testMaxLifetimeCanBeConfigured(): void
    {
        $options = PoolOptions::fromArray(['max_lifetime' => 120.0]);

        $this->assertSame(120.0, $options->maxLifetime);
    }

    public function testOptionsCannotBeChangedAfterConstruction(): void
    {
        $options = PoolOptions::fromArray([]);

        $this->expectException(Error::class);
        $options->maxLifetime = 30.0;
    }

    public function testJitteredLifetimeDeadlineDefaultsToDisabled(): void
    {
        $this->assertNull(PoolOptions::fromArray([])->jitteredLifetimeDeadline(100.0));
    }

    public function testJitteredLifetimeDeadlineKeepsConfiguredLifetimeAsUpperBound(): void
    {
        $createdAt = 100.0;
        $maxLifetime = 60.0;

        $deadline = PoolOptions::fromArray(['max_lifetime' => $maxLifetime])->jitteredLifetimeDeadline($createdAt);

        $this->assertGreaterThanOrEqual(
            $createdAt + ($maxLifetime * PoolOptions::MIN_LIFETIME_JITTER_BASIS / PoolOptions::LIFETIME_JITTER_SCALE),
            $deadline
        );
        $this->assertLessThanOrEqual($createdAt + $maxLifetime, $deadline);
    }

    public function testConfiguredValuesAreNormalized(): void
    {
        $events = [ConnectionReleasing::class, CustomPoolEvent::class];
        $options = PoolOptions::fromArray([
            'min_retained_connections' => 0,
            'max_connections' => 20,
            'connect_timeout' => 2,
            'wait_timeout' => 2.5,
            'heartbeat_interval' => 5,
            'heartbeat_timeout' => 0.5,
            'idle_check_interval' => 3,
            'max_idle_time' => 30,
            'max_lifetime' => 120,
            'events' => $events,
        ]);

        $this->assertSame(0, $options->minRetainedConnections);
        $this->assertSame(20, $options->maxConnections);
        $this->assertSame(2.0, $options->connectTimeout);
        $this->assertSame(2.5, $options->waitTimeout);
        $this->assertSame(5.0, $options->heartbeatInterval);
        $this->assertSame(0.5, $options->heartbeatTimeout);
        $this->assertSame(3.0, $options->idleCheckInterval);
        $this->assertSame(30.0, $options->maxIdleTime);
        $this->assertSame(120.0, $options->maxLifetime);
        $this->assertSame($events, $options->events);
    }

    public function testNullDisablesOptionalDurations(): void
    {
        $options = PoolOptions::fromArray([
            'heartbeat_interval' => null,
            'idle_check_interval' => null,
            'max_idle_time' => null,
            'max_lifetime' => null,
        ]);

        $this->assertNull($options->heartbeatInterval);
        $this->assertNull($options->idleCheckInterval);
        $this->assertNull($options->maxIdleTime);
        $this->assertNull($options->maxLifetime);
        $this->assertNull($options->jitteredLifetimeDeadline(100.0));
    }

    #[DataProvider('invalidOptions')]
    public function testInvalidOptionsAreRejected(array $options, string $field): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains("[{$field}]");

        PoolOptions::fromArray($options);
    }

    public static function invalidOptions(): iterable
    {
        yield 'negative minimum' => [['min_retained_connections' => -1], 'min_retained_connections'];
        yield 'zero maximum' => [['max_connections' => 0], 'max_connections'];
        yield 'minimum exceeds maximum' => [
            ['min_retained_connections' => 2, 'max_connections' => 1], 'min_retained_connections',
        ];

        foreach (['min_retained_connections', 'max_connections'] as $field) {
            foreach ([null, true, '2', 2.5] as $index => $value) {
                yield "{$field} type {$index}" => [[$field => $value], $field];
            }
        }

        foreach (['connect_timeout', 'wait_timeout', 'heartbeat_timeout'] as $field) {
            yield "{$field} null" => [[$field => null], $field];
        }

        foreach ([
            'connect_timeout', 'wait_timeout', 'heartbeat_interval', 'heartbeat_timeout',
            'idle_check_interval', 'max_idle_time', 'max_lifetime',
        ] as $field) {
            foreach ([0, -1, -2.0, NAN, INF, -INF, true, '1', []] as $index => $value) {
                yield "{$field} invalid {$index}" => [[$field => $value], $field];
            }
        }

        foreach ([null, 'event', ['event' => CustomPoolEvent::class], [''], [1], ['MissingPoolEvent']] as $index => $events) {
            yield "events {$index}" => [['events' => $events], 'events'];
        }

        foreach (['min_connections', 'heartbeat', 'unknown'] as $field) {
            yield "unknown {$field}" => [[$field => 1], $field];
        }
    }
}

class CustomPoolEvent
{
}
