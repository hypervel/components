<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Passport\Client;
use Hypervel\Passport\ClientRepository;
use Hypervel\Passport\Database\Factories\SigningKeyFactory;
use Hypervel\Passport\Passport;
use Hypervel\Passport\PassportServiceProvider;
use Hypervel\Passport\Token;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Passport\Fixtures\User;
use PDO;
use PHPUnit\Framework\Attributes\TestWith;
use UnitEnum;

class RelationConnectionsTest extends TestCase
{
    /**
     * Register real authentication and issuance services.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [PassportServiceProvider::class];
    }

    /**
     * Keep user, client and credential storage independently addressable.
     */
    protected function defineEnvironment(Application $app): void
    {
        $keys = (new SigningKeyFactory)->definition();
        $app->make('config')->set([
            'database.connections.passport_users' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'database.connections.passport_clients' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'auth.providers.users.model' => PassportConnectionUser::class,
            'passport.connection' => null,
            'passport.private_key' => $keys['private_key'],
            'passport.public_key' => $keys['public_key'],
            'passport.cache.enabled' => true,
            'passport.cache.store' => 'passport-relations',
            'cache.serializable_classes' => [Token::class, Client::class, PassportConnectionClient::class],
            'cache.stores.passport-relations' => [
                'driver' => 'file', 'path' => $app->storagePath('framework/cache/data/passport-relations'),
            ],
        ]);
    }

    /**
     * Remove this class's shared cache entries.
     */
    protected function tearDown(): void
    {
        try {
            $this->app->make('cache')->store('passport-relations')->flush();
        } finally {
            parent::tearDown();
        }
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testCreationAuthenticationEagerLoadingAndCachedRelationWritesUseConfiguredConnections(bool $separateClients): void
    {
        DB::connection('passport_users')->getSchemaBuilder()->create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        (require __DIR__ . '/../../src/passport/database/migrations/2016_06_01_000002_create_oauth_access_tokens_table.php')->up();
        $default = DB::getDefaultConnection();

        if ($separateClients) {
            Passport::useClientModel(PassportConnectionClient::class);
            DB::setDefaultConnection('passport_clients');
        }

        try {
            (require __DIR__ . '/../../src/passport/database/migrations/2016_06_01_000004_create_oauth_clients_table.php')->up();
        } finally {
            DB::setDefaultConnection($default);
        }

        $user = PassportConnectionUser::create(['name' => 'User']);
        $clients = $this->app->make(ClientRepository::class);
        $owned = $clients->createAuthorizationCodeGrantClient('Owned', ['https://client.example/callback'], user: $user);
        $personal = $clients->createPersonalAccessGrantClient('Personal', 'users');
        $result = $user->createToken('Test');
        $connection = Passport::token()->getConnection();
        $reader = $connection->getReadPdo();
        $replica = new PDO('sqlite::memory:');
        $replica->exec('create table oauth_access_tokens (id text primary key)');
        $connection->setReadPdo($replica);

        try {
            $this->assertNull(Token::query()->find($result->accessTokenId));
            $this->assertSame($result->accessTokenId, $result->token->id);
            $this->assertNull(Token::query()->find($result->accessTokenId));
        } finally {
            $connection->setReadPdo($reader);
        }

        Route::get('/passport-user', static fn (): string => request()->user()->name)->middleware('auth:passport');
        $this->withToken($result->accessToken)->get('/passport-user')->assertOk()->assertSee('User');
        $this->assertSame($owned->id, $clients->findActive($owned->id)->id);

        $loaded = $user->fresh()->load('tokens.client', 'oauthApps');
        $this->assertSame([$result->token->id], $loaded->tokens->modelKeys());
        $this->assertSame($personal->id, $loaded->tokens->sole()->client->id);
        $this->assertSame([$owned->id], $loaded->oauthApps->modelKeys());
        $this->assertSame($user->id, $owned->owner->id);
        $this->assertSame($result->token->id, $personal->tokens()->sole()->id);

        $this->assertSame(1, $user->tokens()->update(['revoked' => true]));
        $this->assertTrue(Token::findToken($result->token->id)->revoked);
        $this->withToken($result->accessToken)->getJson('/passport-user')->assertUnauthorized();
        $this->assertSame(1, $user->tokens()->delete());
        $this->assertNull(Token::findToken($result->token->id));

        $this->assertSame(1, $user->oauthApps()->update(['name' => 'Renamed']));
        $this->assertSame('Renamed', $clients->find($owned->id)->name);
        $this->assertSame(1, $user->oauthApps()->delete());
        $this->assertNull($clients->find($owned->id));
        $this->assertSame($personal->id, $clients->findActive($personal->id)->id);
    }

    public function testTokenClientRelationKeepsItsForeignKeyWithACustomClientPrimaryKey(): void
    {
        Passport::useClientModel(PassportCustomKeyClient::class);
        DB::connection()->getSchemaBuilder()->create('oauth_clients', static function (Blueprint $table): void {
            $table->uuid('client_uuid')->primary();
            $table->timestamps();
        });
        (require __DIR__ . '/../../src/passport/database/migrations/2016_06_01_000002_create_oauth_access_tokens_table.php')->up();

        $client = PassportCustomKeyClient::create();
        $token = $client->tokens()->create(['id' => 'token', 'revoked' => false]);

        $this->assertSame($client->getKey(), $token->client_id);
        $this->assertSame($client->getKey(), $token->client->getKey());
        $this->assertSame($client->getKey(), $token->fresh()->load('client')->client->getKey());
    }
}

class PassportConnectionUser extends User
{
    protected ?string $table = 'users';

    protected UnitEnum|string|null $connection = 'passport_users';
}

class PassportConnectionClient extends Client
{
    protected UnitEnum|string|null $connection = 'passport_clients';
}

class PassportCustomKeyClient extends Client
{
    protected ?string $primaryKey = 'client_uuid';
}
