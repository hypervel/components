<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel;

use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Integration\Database\Laravel\Fixtures\NamedScopeUser;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 * @coversNothing
 */
#[WithMigration]
class EloquentNamedScopeAttributeTest extends TestCase
{
    protected string $query = 'select * from "named_scope_users" where "email_verified_at" is not null';

    protected function setUp(): void
    {
        parent::setUp();

        $this->markTestSkippedUnless(
            $this->usesSqliteInMemoryDatabaseConnection(),
            'Requires in-memory database connection',
        );
    }

    #[DataProvider('scopeDataProvider')]
    public function testItCanQueryNamedScopedFromTheQueryBuilder(string $methodName): void
    {
        $query = NamedScopeUser::query()->{$methodName}(true);

        $this->assertSame($this->query, $query->toRawSql());
    }

    #[DataProvider('scopeDataProvider')]
    public function testItCanQueryNamedScopedFromStaticQuery(string $methodName): void
    {
        $query = NamedScopeUser::{$methodName}(true);

        $this->assertSame($this->query, $query->toRawSql());
    }

    public static function scopeDataProvider(): array
    {
        return [
            'scope with return' => ['verified'],
            'scope without return' => ['verifiedWithoutReturn'],
        ];
    }
}
