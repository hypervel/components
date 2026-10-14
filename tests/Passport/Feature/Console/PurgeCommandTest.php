<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Feature\Console;

use Hypervel\Passport\Database\Factories\ClientFactory;
use Hypervel\Passport\Passport;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\DB;
use Hypervel\Tests\Passport\Feature\PassportTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

// REMOVED: Console/HashCommandTest covers the pre-13 passport:hash upgrade command; client secrets are always stored as SHA-256 digests.
class PurgeCommandTest extends PassportTestCase
{
    /**
     * Run the lazy migrations before entering dry-run mode.
     */
    protected function setUpInCoroutine(): void
    {
        DB::select('select 1');
    }

    public function testItCanPurgeTokens(): void
    {
        $this->travelTo(CarbonImmutable::create(2000, 1, 8));

        $query = DB::pretend(function () {
            $this->artisan('passport:purge')
                ->expectsOutputToContain('Purged revoked items and items expired for more than 1 week.');
        });

        $this->assertSame([
            'select "oauth_access_tokens"."id" from "oauth_access_tokens" where ("revoked" = 1 or "expires_at" < \'2000-01-01 00:00:00\') and not exists (select * from "oauth_refresh_tokens" where ("oauth_access_tokens"."id" = "oauth_refresh_tokens"."access_token_id") and ("revoked" = 0 and ("expires_at" is null or "expires_at" > \'2000-01-08 00:00:00\'))) and "id" is not null order by "id" asc limit 1000',
            'select "oauth_auth_codes"."id" from "oauth_auth_codes" where ("revoked" = 1 or "expires_at" < \'2000-01-01 00:00:00\') and "id" is not null order by "id" asc limit 1000',
            'select "oauth_refresh_tokens"."id" from "oauth_refresh_tokens" where ("revoked" = 1 or "expires_at" < \'2000-01-01 00:00:00\') and "expires_at" <= \'2000-01-08 00:00:00\' and "id" is not null order by "id" asc limit 1000',
            'select "oauth_refresh_token_families"."id" from "oauth_refresh_token_families" where not exists (select * from "oauth_refresh_tokens" where "oauth_refresh_token_families"."id" = "oauth_refresh_tokens"."family_id") and "id" is not null order by "id" asc limit 1000',
            'select "oauth_device_codes"."id" from "oauth_device_codes" where ("revoked" = 1 or "expires_at" < \'2000-01-01 00:00:00\') and "id" is not null order by "id" asc limit 1000',
        ], array_column($query, 'query'));
    }

    public function testItCanPurgeRevokedTokens(): void
    {
        $this->travelTo(CarbonImmutable::create(2000, 1, 8));

        $query = DB::pretend(function () {
            $this->artisan('passport:purge', ['--revoked' => true])
                ->expectsOutputToContain('Purged revoked items.');
        });

        $this->assertSame([
            'select "oauth_access_tokens"."id" from "oauth_access_tokens" where ("revoked" = 1) and not exists (select * from "oauth_refresh_tokens" where ("oauth_access_tokens"."id" = "oauth_refresh_tokens"."access_token_id") and ("revoked" = 0 and ("expires_at" is null or "expires_at" > \'2000-01-08 00:00:00\'))) and "id" is not null order by "id" asc limit 1000',
            'select "oauth_auth_codes"."id" from "oauth_auth_codes" where ("revoked" = 1) and "id" is not null order by "id" asc limit 1000',
            'select "oauth_refresh_tokens"."id" from "oauth_refresh_tokens" where ("revoked" = 1) and "expires_at" <= \'2000-01-08 00:00:00\' and "id" is not null order by "id" asc limit 1000',
            'select "oauth_refresh_token_families"."id" from "oauth_refresh_token_families" where not exists (select * from "oauth_refresh_tokens" where "oauth_refresh_token_families"."id" = "oauth_refresh_tokens"."family_id") and "id" is not null order by "id" asc limit 1000',
            'select "oauth_device_codes"."id" from "oauth_device_codes" where ("revoked" = 1) and "id" is not null order by "id" asc limit 1000',
        ], array_column($query, 'query'));
    }

    public function testItCanPurgeExpiredTokens(): void
    {
        $this->travelTo(CarbonImmutable::create(2000, 1, 8));

        $query = DB::pretend(function () {
            $this->artisan('passport:purge', ['--expired' => true])
                ->expectsOutputToContain('Purged items expired for more than 1 week.');
        });

        $this->assertSame([
            'select "oauth_access_tokens"."id" from "oauth_access_tokens" where ("expires_at" < \'2000-01-01 00:00:00\') and not exists (select * from "oauth_refresh_tokens" where ("oauth_access_tokens"."id" = "oauth_refresh_tokens"."access_token_id") and ("revoked" = 0 and ("expires_at" is null or "expires_at" > \'2000-01-08 00:00:00\'))) and "id" is not null order by "id" asc limit 1000',
            'select "oauth_auth_codes"."id" from "oauth_auth_codes" where ("expires_at" < \'2000-01-01 00:00:00\') and "id" is not null order by "id" asc limit 1000',
            'select "oauth_refresh_tokens"."id" from "oauth_refresh_tokens" where ("expires_at" < \'2000-01-01 00:00:00\') and "expires_at" <= \'2000-01-08 00:00:00\' and "id" is not null order by "id" asc limit 1000',
            'select "oauth_refresh_token_families"."id" from "oauth_refresh_token_families" where not exists (select * from "oauth_refresh_tokens" where "oauth_refresh_token_families"."id" = "oauth_refresh_tokens"."family_id") and "id" is not null order by "id" asc limit 1000',
            'select "oauth_device_codes"."id" from "oauth_device_codes" where ("expires_at" < \'2000-01-01 00:00:00\') and "id" is not null order by "id" asc limit 1000',
        ], array_column($query, 'query'));
    }

    public function testItCanPurgeRevokedAndExpiredTokens(): void
    {
        $this->travelTo(CarbonImmutable::create(2000, 1, 8));

        $query = DB::pretend(function () {
            $this->artisan('passport:purge', ['--revoked' => true, '--expired' => true])
                ->expectsOutputToContain('Purged revoked items and items expired for more than 1 week.');
        });

        $this->assertSame([
            'select "oauth_access_tokens"."id" from "oauth_access_tokens" where ("revoked" = 1 or "expires_at" < \'2000-01-01 00:00:00\') and not exists (select * from "oauth_refresh_tokens" where ("oauth_access_tokens"."id" = "oauth_refresh_tokens"."access_token_id") and ("revoked" = 0 and ("expires_at" is null or "expires_at" > \'2000-01-08 00:00:00\'))) and "id" is not null order by "id" asc limit 1000',
            'select "oauth_auth_codes"."id" from "oauth_auth_codes" where ("revoked" = 1 or "expires_at" < \'2000-01-01 00:00:00\') and "id" is not null order by "id" asc limit 1000',
            'select "oauth_refresh_tokens"."id" from "oauth_refresh_tokens" where ("revoked" = 1 or "expires_at" < \'2000-01-01 00:00:00\') and "expires_at" <= \'2000-01-08 00:00:00\' and "id" is not null order by "id" asc limit 1000',
            'select "oauth_refresh_token_families"."id" from "oauth_refresh_token_families" where not exists (select * from "oauth_refresh_tokens" where "oauth_refresh_token_families"."id" = "oauth_refresh_tokens"."family_id") and "id" is not null order by "id" asc limit 1000',
            'select "oauth_device_codes"."id" from "oauth_device_codes" where ("revoked" = 1 or "expires_at" < \'2000-01-01 00:00:00\') and "id" is not null order by "id" asc limit 1000',
        ], array_column($query, 'query'));
    }

    public function testItCanPurgeTokensByHours(): void
    {
        $this->travelTo(CarbonImmutable::create(2000, 1, 1, 2));

        $query = DB::pretend(function () {
            $this->artisan('passport:purge', ['--hours' => 2])
                ->expectsOutputToContain('Purged revoked items and items expired for more than 2 hours.');
        });

        $this->assertSame([
            'select "oauth_access_tokens"."id" from "oauth_access_tokens" where ("revoked" = 1 or "expires_at" < \'2000-01-01 00:00:00\') and not exists (select * from "oauth_refresh_tokens" where ("oauth_access_tokens"."id" = "oauth_refresh_tokens"."access_token_id") and ("revoked" = 0 and ("expires_at" is null or "expires_at" > \'2000-01-01 02:00:00\'))) and "id" is not null order by "id" asc limit 1000',
            'select "oauth_auth_codes"."id" from "oauth_auth_codes" where ("revoked" = 1 or "expires_at" < \'2000-01-01 00:00:00\') and "id" is not null order by "id" asc limit 1000',
            'select "oauth_refresh_tokens"."id" from "oauth_refresh_tokens" where ("revoked" = 1 or "expires_at" < \'2000-01-01 00:00:00\') and "expires_at" <= \'2000-01-01 02:00:00\' and "id" is not null order by "id" asc limit 1000',
            'select "oauth_refresh_token_families"."id" from "oauth_refresh_token_families" where not exists (select * from "oauth_refresh_tokens" where "oauth_refresh_token_families"."id" = "oauth_refresh_tokens"."family_id") and "id" is not null order by "id" asc limit 1000',
            'select "oauth_device_codes"."id" from "oauth_device_codes" where ("revoked" = 1 or "expires_at" < \'2000-01-01 00:00:00\') and "id" is not null order by "id" asc limit 1000',
        ], array_column($query, 'query'));
    }

    public function testPurgeBatchesDeletionsAndPreservesActiveRefreshesAndReuseEvidence(): void
    {
        $this->travelTo(CarbonImmutable::create(2000, 1, 8));
        $client = ClientFactory::new()->create();
        $expired = now()->subWeeks(2);
        $future = now()->addWeek();
        $tokens = [];

        for ($index = 0; $index < 1001; ++$index) {
            $tokens[] = [
                'id' => sprintf('expired-%04d', $index),
                'client_id' => $client->getKey(),
                'revoked' => false,
                'expires_at' => $expired,
            ];
        }

        Passport::token()->newQuery()->insert($tokens);

        foreach (['active', 'refresh-future', 'refresh-indefinite', 'used-refresh', 'expired-refresh'] as $id) {
            Passport::token()->newQuery()->create([
                'id' => $id,
                'client_id' => $client->getKey(),
                'revoked' => $id !== 'active',
                'expires_at' => $future,
            ]);
        }

        $liveFamily = Passport::refreshTokenFamily()->newQuery()->create();
        $expiredFamily = Passport::refreshTokenFamily()->newQuery()->create();
        $emptyFamily = Passport::refreshTokenFamily()->newQuery()->create();

        foreach ([
            ['refresh-future', false, $future, $liveFamily->getKey()],
            ['refresh-indefinite', false, null, $liveFamily->getKey()],
            ['used-refresh', true, $future, $liveFamily->getKey()],
            ['expired-refresh', true, $expired, $expiredFamily->getKey()],
        ] as [$id, $revoked, $expiration, $familyId]) {
            Passport::refreshToken()->newQuery()->create([
                'id' => $id,
                'access_token_id' => $id,
                'family_id' => $familyId,
                'revoked' => $revoked,
                'expires_at' => $expiration,
            ]);
        }

        foreach (['active' => false, 'revoked' => true] as $id => $revoked) {
            Passport::authCode()->newQuery()->create([
                'id' => $id,
                'user_id' => 1,
                'client_id' => $client->getKey(),
                'revoked' => $revoked,
                'expires_at' => $future,
            ]);
            Passport::deviceCode()->newQuery()->create([
                'id' => $id,
                'user_code' => $id,
                'client_id' => $client->getKey(),
                'scopes' => [],
                'revoked' => $revoked,
                'expires_at' => $future,
            ]);
        }

        $this->artisan('passport:purge')->assertSuccessful();

        $this->assertSame(['active', 'refresh-future', 'refresh-indefinite'], Passport::token()->newQuery()->orderBy('id')->pluck('id')->all());
        $this->assertSame(['refresh-future', 'refresh-indefinite', 'used-refresh'], Passport::refreshToken()->newQuery()->orderBy('id')->pluck('id')->all());
        $this->assertSame([$liveFamily->getKey()], Passport::refreshTokenFamily()->newQuery()->pluck('id')->all());
        $this->assertModelMissing($expiredFamily);
        $this->assertModelMissing($emptyFamily);
        $this->assertSame(['active'], Passport::authCode()->newQuery()->pluck('id')->all());
        $this->assertSame(['active'], Passport::deviceCode()->newQuery()->pluck('id')->all());

        $this->travelTo($future->addWeek()->addSecond());
        $this->artisan('passport:purge')->assertSuccessful();

        $this->assertDatabaseCount('oauth_refresh_tokens', 1);
        $this->assertDatabaseHas('oauth_refresh_tokens', ['id' => 'refresh-indefinite']);
        $this->assertDatabaseCount('oauth_refresh_token_families', 1);
    }

    #[DataProvider('invalidHours')]
    public function testHoursMustBeANonNegativeInteger(string $hours): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The --hours option must be a non-negative integer.');

        $this->artisan('passport:purge', ['--hours' => $hours])->run();
    }

    /**
     * Provide invalid retention periods.
     */
    public static function invalidHours(): array
    {
        return [['-1'], ['1.5'], ['invalid']];
    }
}
