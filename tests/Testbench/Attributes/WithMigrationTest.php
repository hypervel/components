<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Attributes;

use Hypervel\Database\Migrations\Migrator;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Testbench\Contracts\Attributes\Invokable;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

use function Hypervel\Testbench\default_migration_path;

class WithMigrationTest extends TestCase
{
    #[Test]
    public function itDefaultsToTheHypervelMigrationSet(): void
    {
        $attribute = new WithMigration;

        $this->assertInstanceOf(Invokable::class, $attribute);
        $this->assertSame(['hypervel'], $attribute->types);
    }

    #[Test]
    public function itMapsFrameworkAliasesToTheHypervelMigrationSet(): void
    {
        $this->assertSame(['hypervel'], (new WithMigration('cache'))->types);
        $this->assertSame(['hypervel'], (new WithMigration('queue'))->types);
        $this->assertSame(['hypervel'], (new WithMigration('session'))->types);
    }

    #[Test]
    public function itDeduplicatesMigrationSetsAfterResolvingAliases(): void
    {
        $this->assertSame(
            ['hypervel', 'notifications'],
            (new WithMigration('cache', 'queue', 'hypervel', 'notifications', 'notifications'))->types,
        );
    }

    #[Test]
    public function itPreservesNamedMigrationSets(): void
    {
        $this->assertSame(
            ['hypervel', 'notifications'],
            (new WithMigration('queue', 'notifications'))->types,
        );
    }

    #[Test]
    public function itRegistersNamedMigrationSetsWithTheMigrator(): void
    {
        (new WithMigration('notifications'))($this->app);

        $this->assertContains(
            default_migration_path('notifications'),
            $this->app->make(Migrator::class)->paths(),
        );
    }

    #[Test]
    public function itRejectsUnknownMigrationSets(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing-migration-set');

        (new WithMigration('missing-migration-set'))($this->app);
    }
}
