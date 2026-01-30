<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel;

use Hypervel\Tests\Integration\Database\DatabaseTestCase;
use Hypervel\Tests\Integration\Database\Laravel\Fixtures\NamedScopeUser;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 * @coversNothing
 */
class EloquentNamedScopeAttributeTest extends DatabaseTestCase
{
    protected string $query = 'select * from "named_scope_users" where "email_verified_at" is not null';

    #[DataProvider('scopeDataProvider')]
    public function testItCanQueryNamedScopedFromTheQueryBuilder(string $methodName)
    {
        $query = NamedScopeUser::query()->{$methodName}(true);

        $this->assertSame($this->query, $query->toRawSql());
    }

    #[DataProvider('scopeDataProvider')]
    public function testItCanQueryNamedScopedFromStaticQuery(string $methodName)
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
