<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database;

use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Tests\Integration\Database\Fixtures\NamedScopeUser;
use PHPUnit\Framework\Attributes\DataProvider;

#[WithMigration]
class EloquentNamedScopeAttributeTest extends DatabaseTestCase
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
