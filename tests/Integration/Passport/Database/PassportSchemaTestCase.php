<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Passport\Database;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Passport\Client;
use Hypervel\Passport\ClientRepository;
use Hypervel\Passport\Consent;
use Hypervel\Passport\Passport;
use Hypervel\Passport\PassportServiceProvider;
use Hypervel\Passport\SigningKey;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\TestWith;

abstract class PassportSchemaTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * Register Passport's configuration and model services.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [PassportServiceProvider::class];
    }

    /**
     * Exercise the shipped schema on the selected database driver.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 4) . '/src/passport/database/migrations');
    }

    #[TestWith(['users'])]
    #[TestWith(['staff'])]
    public function testPersonalAccessSelectionFiltersGrantAndProviderBeforeCheckingClientAccess(string $provider): void
    {
        $attributes = [
            'name' => 'Personal client', 'secret' => null, 'revoked' => false,
            'provider' => $provider === 'users' ? null : $provider,
            'grant_types' => ['personal_access'], 'redirect_uris' => [],
            'created_at' => '2026-01-01 00:00:00',
        ];
        $allowed = Client::forceCreate($attributes);
        $denied = Client::forceCreate([...$attributes, 'created_at' => '2026-01-02 00:00:00']);

        foreach ([
            ['provider' => 'other'],
            ['grant_types' => ['authorization_code']],
            ['revoked' => true],
        ] as $ineligible) {
            Client::forceCreate([...$attributes, 'created_at' => '2026-01-03 00:00:00', ...$ineligible]);
        }

        $checked = [];
        Passport::authenticateClientsUsing(static function (Client $client) use ($allowed, &$checked): bool {
            $checked[] = $client->id;

            return $client->is($allowed);
        });
        DB::enableQueryLog();
        $selected = $this->app->make(ClientRepository::class)->personalAccessClient($provider);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertTrue($selected->is($allowed));
        $this->assertSame([$denied->id, $allowed->id], $checked);
        $this->assertCount(1, $queries);
        $this->assertSame(['personal_access'], $selected->grant_types);
    }

    public function testCredentialSchemaUsesTheDriverJsonTypeAndLookupIndexes(): void
    {
        $jsonType = match (DB::connection()->getDriverName()) {
            'pgsql' => 'jsonb',
            'mysql' => 'json',
            'mariadb' => 'longtext',
            'sqlite' => 'text',
        };

        foreach ([
            'oauth_clients' => ['grant_types', 'redirect_uris'],
            'oauth_auth_codes' => ['scopes', 'resources'],
            'oauth_access_tokens' => ['scopes', 'audience'],
            'oauth_device_codes' => ['scopes', 'resources'],
            'oauth_refresh_tokens' => ['resources'],
            'oauth_consents' => ['scopes', 'permissions'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertSame($jsonType, Schema::getColumnType($table, $column));
            }
        }

        $consent = Consent::factory()->create(['scopes' => null]);
        $this->assertNull($consent->fresh()->scopes);

        $this->assertTrue(Schema::hasIndex('oauth_access_tokens', ['user_id', 'provider', 'client_id']));
        $this->assertTrue(Schema::hasIndex('oauth_access_tokens', ['client_id']));
        $this->assertTrue(Schema::hasIndex('oauth_refresh_tokens', ['family_id']));
        $this->assertTrue(Schema::hasIndex('oauth_device_codes', ['user_code'], 'unique'));

        foreach (['oauth_access_tokens', 'oauth_auth_codes', 'oauth_refresh_tokens', 'oauth_device_codes'] as $table) {
            $this->assertTrue(Schema::hasIndex($table, ['expires_at']));
        }

        $this->assertSame(match (DB::connection()->getDriverName()) {
            'pgsql' => 'int8',
            'mysql', 'mariadb' => 'bigint',
            'sqlite' => 'integer',
        }, Schema::getColumnType('oauth_signing_keys', 'max_token_lifetime'));
        $key = SigningKey::factory()->create(['max_token_lifetime' => 5_000_000_000]);
        $this->assertSame(5_000_000_000, $key->fresh()->max_token_lifetime);
    }
}
