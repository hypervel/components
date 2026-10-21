<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Passport\Database\MySql;

use Hypervel\Cache\ModelCacheCoordinator;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Passport\Client;
use Hypervel\Passport\Token;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Passport\Database\PassportSchemaTestCase;
use PDO;

#[RequiresDatabase('mysql')]
class PassportSchemaTest extends PassportSchemaTestCase
{
    public function testCachedMutationOffsetsDoNotBecomeOversizedBindingLists(): void
    {
        $client = Client::factory()->create();
        $rows = [];

        for ($index = 0; $index < 65_538; ++$index) {
            $rows[] = ['id' => sprintf('token-%05d', $index), 'client_id' => $client->id, 'revoked' => false];

            if (count($rows) === 1000 || $index === 65_537) {
                Token::query()->insert($rows);
                $rows = [];
            }
        }

        $this->assertFalse(DB::connection()->getPdo()->getAttribute(PDO::ATTR_EMULATE_PREPARES));
        $invalidated = [];
        $affected = $this->app->make(ModelCacheCoordinator::class)->mutate(
            $client->tokens()->orderBy('id')->offset(65_536)->getQuery(),
            static fn (Builder $query): int => $query->delete(),
            static function (array $ids) use (&$invalidated): void {
                $invalidated = array_merge($invalidated, $ids);
            },
        );

        $this->assertSame(2, $affected);
        $this->assertSame(['token-65536', 'token-65537'], $invalidated);
        $this->assertSame(65_536, $client->tokens()->count());
    }
}
