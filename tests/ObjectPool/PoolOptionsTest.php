<?php

declare(strict_types=1);

namespace Hypervel\Tests\ObjectPool;

use Hypervel\ObjectPool\PoolOptions;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

class PoolOptionsTest extends TestCase
{
    public function testDefaultsAreNormalized(): void
    {
        $options = PoolOptions::fromArray([]);

        $this->assertSame([
            'min_retained_objects' => 1,
            'max_objects' => 10,
            'wait_timeout' => 3.0,
            'max_lifetime' => 60.0,
            'max_idle_time' => null,
            'pool_idle_timeout' => PoolOptions::DEFAULT_POOL_IDLE_TIMEOUT,
        ], $options->toArray());
    }

    public function testExplicitValuesAreNormalized(): void
    {
        $options = PoolOptions::fromArray([
            'min_retained_objects' => 0,
            'max_objects' => 20,
            'wait_timeout' => 4,
            'max_lifetime' => null,
            'max_idle_time' => 15,
            'pool_idle_timeout' => 600,
        ]);

        $this->assertSame([
            'min_retained_objects' => 0,
            'max_objects' => 20,
            'wait_timeout' => 4.0,
            'max_lifetime' => null,
            'max_idle_time' => 15.0,
            'pool_idle_timeout' => 600.0,
        ], $options->toArray());
    }

    public function testExplicitNullDisablesOptionalDurations(): void
    {
        $options = PoolOptions::fromArray([
            'max_lifetime' => null,
            'max_idle_time' => null,
            'pool_idle_timeout' => null,
        ]);

        $this->assertNull($options->maxLifetime);
        $this->assertNull($options->maxIdleTime);
        $this->assertNull($options->poolIdleTimeout);
        $this->assertSame(
            PoolOptions::DEFAULT_POOL_IDLE_TIMEOUT,
            PoolOptions::fromArray([])->poolIdleTimeout
        );
        $this->assertSame(60.0, PoolOptions::fromArray([])->maxLifetime);
        $this->assertFalse($options->equals(PoolOptions::fromArray([])));
    }

    public function testEquivalentInputsCompareEqualRegardlessOfDefaultsAndKeyOrder(): void
    {
        $defaults = PoolOptions::fromArray([]);
        $explicit = PoolOptions::fromArray([
            'pool_idle_timeout' => 300,
            'max_idle_time' => null,
            'max_lifetime' => 60,
            'wait_timeout' => 3,
            'max_objects' => 10,
            'min_retained_objects' => 1,
        ]);

        $this->assertTrue($defaults->equals($explicit));
        $this->assertTrue($explicit->equals($defaults));
        $this->assertFalse($defaults->equals(PoolOptions::fromArray(['max_objects' => 11])));
    }

    public function testUnknownOptionsAreRejectedWithTheKnownOptions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown pool option(s) [typo]. Known options are [min_retained_objects, max_objects, wait_timeout, max_lifetime, max_idle_time, pool_idle_timeout].');

        PoolOptions::fromArray(['typo' => true]);
    }

    #[DataProvider('invalidCountTypes')]
    public function testCountOptionsRequireIntegers(string $name, mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Pool option [{$name}] must be an integer.");

        PoolOptions::fromArray([$name => $value]);
    }

    public static function invalidCountTypes(): array
    {
        return [
            ['min_retained_objects', 1.0],
            ['min_retained_objects', '1'],
            ['min_retained_objects', true],
            ['min_retained_objects', null],
            ['max_objects', 10.0],
            ['max_objects', '10'],
            ['max_objects', false],
            ['max_objects', null],
        ];
    }

    #[DataProvider('invalidDurationTypes')]
    public function testDurationsRequireIntegersOrFloats(string $name, mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Pool option [{$name}] must be an integer or float.");

        PoolOptions::fromArray([$name => $value]);
    }

    public static function invalidDurationTypes(): array
    {
        return [
            ['wait_timeout', '3'],
            ['wait_timeout', true],
            ['wait_timeout', null],
            ['max_lifetime', '60'],
            ['max_lifetime', false],
            ['max_idle_time', '1'],
            ['max_idle_time', true],
            ['pool_idle_timeout', '300'],
            ['pool_idle_timeout', false],
        ];
    }

    #[DataProvider('nonFiniteDurations')]
    public function testDurationsMustBeFinite(string $name, float $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Pool option [{$name}] must be finite.");

        PoolOptions::fromArray([$name => $value]);
    }

    public static function nonFiniteDurations(): array
    {
        $cases = [];

        foreach (['wait_timeout', 'max_lifetime', 'max_idle_time', 'pool_idle_timeout'] as $name) {
            foreach ([NAN, INF, -INF] as $value) {
                $cases[] = [$name, $value];
            }
        }

        return $cases;
    }

    #[DataProvider('invalidValues')]
    public function testOptionValuesAreValidated(array $input, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        PoolOptions::fromArray($input);
    }

    public static function invalidValues(): array
    {
        return [
            [['min_retained_objects' => -1], 'Pool option [min_retained_objects] must be at least 0.'],
            [['max_objects' => 0], 'Pool option [max_objects] must be at least 1.'],
            [
                ['min_retained_objects' => 2, 'max_objects' => 1],
                'Pool option [min_retained_objects] must not exceed [max_objects].',
            ],
            [['wait_timeout' => 0], 'Pool option [wait_timeout] must be greater than 0.'],
            [['wait_timeout' => -1], 'Pool option [wait_timeout] must be greater than 0.'],
            [['max_lifetime' => 0], 'Pool option [max_lifetime] must be null or greater than 0.'],
            [['max_lifetime' => -1], 'Pool option [max_lifetime] must be null or greater than 0.'],
            [['max_idle_time' => 0], 'Pool option [max_idle_time] must be null or greater than 0.'],
            [['max_idle_time' => -1], 'Pool option [max_idle_time] must be null or greater than 0.'],
            [['pool_idle_timeout' => 0], 'Pool option [pool_idle_timeout] must be null or greater than 0.'],
            [['pool_idle_timeout' => -1], 'Pool option [pool_idle_timeout] must be null or greater than 0.'],
        ];
    }
}
