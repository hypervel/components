<?php

declare(strict_types=1);

namespace Hypervel\Tests\RateLimiter;

use Hypervel\Config\Repository;
use Hypervel\RateLimiter\AdmissionPolicy;
use Hypervel\RateLimiter\Swoole\TableManager;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;

class SwooleTableManagerTest extends TestCase
{
    public function testCreatesAndCachesAnEightByteIntegerTable(): void
    {
        $manager = $this->manager([
            'swoole' => $this->storeConfig(),
        ]);

        $state = $manager->get('swoole');
        $table = $state->table();

        $this->assertSame($state, $manager->get('swoole'));
        $this->assertTrue($table->set('maximum', [
            'value' => AdmissionPolicy::MAX_INTEGER,
            'secondary_value' => AdmissionPolicy::MAX_INTEGER,
            'expires_at' => AdmissionPolicy::MAX_INTEGER,
        ]));
        $this->assertSame([
            'value' => AdmissionPolicy::MAX_INTEGER,
            'secondary_value' => AdmissionPolicy::MAX_INTEGER,
            'expires_at' => AdmissionPolicy::MAX_INTEGER,
        ], $table->get('maximum'));
    }

    public function testSealingRetainsExistingTablesAndRejectsLateCreation(): void
    {
        $manager = $this->manager([
            'first' => $this->storeConfig(),
            'second' => $this->storeConfig(),
        ]);
        $first = $manager->get('first');

        $manager->seal();

        $this->assertSame($first, $manager->get('first'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('was not initialized before the server fork');

        $manager->get('second');
    }

    public function testRejectsAnUndefinedSwooleStore(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Swoole rate limiter store [missing] is not defined.');

        $this->manager([])->get('missing');
    }

    #[DataProvider('validConflictProportions')]
    public function testAcceptsConflictProportionsThatSwooleHonorsExactly(float $conflictProportion): void
    {
        $manager = $this->manager([
            'swoole' => $this->storeConfig(['conflict_proportion' => $conflictProportion]),
        ]);

        $this->assertSame(64, $manager->get('swoole')->table()->getSize());
    }

    /**
     * @return array<string, array{float}>
     */
    public static function validConflictProportions(): array
    {
        return [
            'minimum' => [0.2],
            'maximum' => [1.0],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('invalidConfigurations')]
    public function testRejectsInvalidStructuralConfiguration(array $overrides): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->manager([
            'swoole' => [...$this->storeConfig(), ...$overrides],
        ])->get('swoole');
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidConfigurations(): array
    {
        return [
            'string rows' => [['rows' => '64']],
            'zero rows' => [['rows' => 0]],
            'string conflict proportion' => [['conflict_proportion' => '0.2']],
            'integer conflict proportion' => [['conflict_proportion' => 1]],
            'zero conflict proportion' => [['conflict_proportion' => 0.0]],
            'below Swoole minimum' => [['conflict_proportion' => 0.1]],
            'above Swoole maximum' => [['conflict_proportion' => 1.5]],
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $stores
     */
    private function manager(array $stores): TableManager
    {
        return new TableManager(new Repository([
            'rate-limiter' => [
                'stores' => $stores,
            ],
        ]));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, float|int|string>
     */
    private function storeConfig(array $overrides = []): array
    {
        return [
            'driver' => 'swoole',
            'rows' => 64,
            'conflict_proportion' => 0.2,
            ...$overrides,
        ];
    }
}
