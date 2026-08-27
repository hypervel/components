<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Sanctum\Database;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Foundation\Auth\User;
use Hypervel\Sanctum\HasApiTokens;
use Hypervel\Sanctum\PersonalAccessToken;
use Hypervel\Sanctum\Sanctum;
use Hypervel\Sanctum\SanctumServiceProvider;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\ServiceProvider;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;
use UnitEnum;

abstract class PersonalAccessTokenCacheTestCase extends DatabaseTestCase
{
    protected const string CACHE_STORE = 'sanctum-database-file';

    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            SanctumServiceProvider::class,
            SanctumDatabaseTestServiceProvider::class,
        ];
    }

    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make('config');
        $defaultConnection = $config->string('database.default');
        $connection = $config->array("database.connections.{$defaultConnection}");

        $config->set([
            'database.connections.sanctum_tokens' => $connection,
            'database.connections.sanctum_owner' => $connection,
            'cache.serializable_classes' => [
                SanctumDatabasePersonalAccessToken::class,
                SanctumDatabaseIntegerUser::class,
                SanctumDatabaseUuidUser::class,
                SanctumDatabaseUlidUser::class,
                SanctumDatabaseDelimitedUser::class,
                SanctumDatabaseSecondaryUser::class,
                SanctumDatabaseSoftDeletingUser::class,
            ],
            'cache.stores.' . self::CACHE_STORE => [
                'driver' => 'file',
                'path' => $app->storagePath('framework/cache/data/sanctum-database'),
            ],
            'sanctum.cache.enabled' => true,
            'sanctum.cache.store' => self::CACHE_STORE,
        ]);
    }

    protected function afterRefreshingDatabase(): void
    {
        $ownerSchema = DB::connection('sanctum_owner')->getSchemaBuilder();

        $ownerSchema->create('sanctum_integer_cache_users', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        $ownerSchema->create('sanctum_uuid_cache_users', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->timestamps();
        });

        $ownerSchema->create('sanctum_ulid_cache_users', static function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->timestamps();
        });

        $ownerSchema->create('sanctum_delimited_cache_users', static function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
            $table->timestamps();
        });

        $ownerSchema->create('sanctum_soft_deleting_cache_users', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });

        $ownerSchema->create(
            'sanctum_secondary_cache_users',
            static function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->timestamps();
            },
        );

        DB::connection('sanctum_tokens')->getSchemaBuilder()->create(
            'personal_access_tokens',
            static function (Blueprint $table): void {
                $table->id();
                $table->string('tokenable_type');
                $table->string('tokenable_id');
                $table->index(['tokenable_type', 'tokenable_id']);
                $table->text('name');
                $table->string('token', 64)->unique();
                $table->text('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->dateTime('expires_at')->nullable()->index();
                $table->timestamps();
            },
        );
    }

    protected function tearDown(): void
    {
        try {
            $this->app->make('cache')->store(self::CACHE_STORE)->flush();
        } finally {
            parent::tearDown();
        }
    }

    public function testTokenableCacheKeysAreStableAcrossPersistedIdentifierTypes(): void
    {
        $owners = [
            SanctumDatabaseIntegerUser::query()->create(['id' => 5, 'name' => 'Integer']),
            SanctumDatabaseUuidUser::query()->create([
                'id' => '550e8400-e29b-41d4-a716-446655440000',
                'name' => 'UUID',
            ]),
            SanctumDatabaseUlidUser::query()->create([
                'id' => '01J5X7M8N9P0Q1R2S3T4V5W6X7',
                'name' => 'ULID',
            ]),
            SanctumDatabaseDelimitedUser::query()->create([
                'id' => 'tenant|segment:' . str_repeat('x', 180),
                'name' => 'Delimited',
            ]),
        ];

        foreach ($owners as $index => $owner) {
            $token = $this->createToken($owner, "secret-{$index}");
            $foundToken = $this->findToken($token, "secret-{$index}");

            $this->assertIsString($foundToken->getAttribute('tokenable_id'));
            $this->assertSame($owner->name, $this->findTokenable($token, "secret-{$index}")?->name);

            $owner->forceFill(['name' => "Updated {$index}"])->save();

            $this->assertSame("Updated {$index}", $this->findTokenable($token, "secret-{$index}")?->name);
        }
    }

    public function testTokenableInvalidationFollowsTheOwnerConnectionTransaction(): void
    {
        $owner = SanctumDatabaseSecondaryUser::query()->create(['name' => 'Original']);
        $token = $this->createToken($owner, 'secret');

        $this->assertSame('Original', $this->findTokenable($token, 'secret')?->name);

        $ownerConnection = DB::connection('sanctum_owner');
        $tokenConnection = DB::connection('sanctum_tokens');

        $ownerConnection->beginTransaction();

        try {
            $owner->forceFill(['name' => 'Rolled back'])->save();
            $ownerConnection->rollBack();
        } finally {
            if ($ownerConnection->transactionLevel() > 0) {
                $ownerConnection->rollBack();
            }
        }

        $this->assertSame('Original', $this->findTokenable($token, 'secret')?->name);

        $owner = SanctumDatabaseSecondaryUser::query()->findOrFail($owner->getKey());
        $tokenConnection->beginTransaction();
        $ownerConnection->beginTransaction();

        try {
            $owner->forceFill(['name' => 'Committed'])->save();

            $tokenConnection->commit();

            $this->assertSame('Original', $this->findTokenable($token, 'secret')?->name);

            $ownerConnection->commit();
        } finally {
            if ($tokenConnection->transactionLevel() > 0) {
                $tokenConnection->rollBack();
            }

            if ($ownerConnection->transactionLevel() > 0) {
                $ownerConnection->rollBack();
            }
        }

        $this->assertSame('Committed', $this->findTokenable($token, 'secret')?->name);
    }

    public function testSoftDeleteRestoreAndForceDeleteInvalidateTheOwnerEntry(): void
    {
        $owner = SanctumDatabaseSoftDeletingUser::query()->create(['name' => 'Owner']);
        $token = $this->createToken($owner, 'secret');

        $this->assertInstanceOf(
            SanctumDatabaseSoftDeletingUser::class,
            $this->findTokenable($token, 'secret'),
        );

        $owner->delete();

        $this->assertNull($this->findTokenable($token, 'secret'));

        $owner->restore();

        $this->assertInstanceOf(
            SanctumDatabaseSoftDeletingUser::class,
            $this->findTokenable($token, 'secret'),
        );

        $owner->forceDelete();

        $this->assertNull($this->findTokenable($token, 'secret'));
    }

    protected function createToken(
        SanctumDatabaseCacheUser $owner,
        string $plainTextToken,
    ): SanctumDatabasePersonalAccessToken {
        return $owner->tokens()->create([
            'name' => 'Test Token',
            'token' => hash('sha256', $plainTextToken),
            'abilities' => ['*'],
        ]);
    }

    protected function findToken(
        SanctumDatabasePersonalAccessToken $token,
        string $plainTextToken,
    ): SanctumDatabasePersonalAccessToken {
        $foundToken = SanctumDatabasePersonalAccessToken::findToken(
            $token->getKey() . '|' . $plainTextToken,
        );

        $this->assertInstanceOf(SanctumDatabasePersonalAccessToken::class, $foundToken);

        return $foundToken;
    }

    protected function findTokenable(
        SanctumDatabasePersonalAccessToken $token,
        string $plainTextToken,
    ): ?SanctumDatabaseCacheUser {
        $tokenable = SanctumDatabasePersonalAccessToken::findTokenable(
            $this->findToken($token, $plainTextToken),
        );

        $this->assertThat(
            $tokenable,
            $this->logicalOr(
                $this->isNull(),
                $this->isInstanceOf(SanctumDatabaseCacheUser::class),
            ),
        );

        return $tokenable;
    }
}

abstract class SanctumDatabaseCacheUser extends User
{
    use HasApiTokens;

    protected UnitEnum|string|null $connection = 'sanctum_owner';

    protected array $guarded = [];
}

class SanctumDatabaseIntegerUser extends SanctumDatabaseCacheUser
{
    protected ?string $table = 'sanctum_integer_cache_users';
}

class SanctumDatabaseUuidUser extends SanctumDatabaseCacheUser
{
    protected ?string $table = 'sanctum_uuid_cache_users';

    protected string $keyType = 'string';

    public bool $incrementing = false;
}

class SanctumDatabaseUlidUser extends SanctumDatabaseCacheUser
{
    protected ?string $table = 'sanctum_ulid_cache_users';

    protected string $keyType = 'string';

    public bool $incrementing = false;
}

class SanctumDatabaseDelimitedUser extends SanctumDatabaseCacheUser
{
    protected ?string $table = 'sanctum_delimited_cache_users';

    protected string $keyType = 'string';

    public bool $incrementing = false;
}

class SanctumDatabaseSecondaryUser extends SanctumDatabaseCacheUser
{
    protected ?string $table = 'sanctum_secondary_cache_users';
}

class SanctumDatabaseSoftDeletingUser extends SanctumDatabaseCacheUser
{
    use SoftDeletes;

    protected ?string $table = 'sanctum_soft_deleting_cache_users';
}

class SanctumDatabasePersonalAccessToken extends PersonalAccessToken
{
    protected UnitEnum|string|null $connection = 'sanctum_tokens';
}

class SanctumDatabaseTestServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the test service provider.
     */
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(SanctumDatabasePersonalAccessToken::class);
    }
}
