<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Auth\Database;

use Hypervel\Auth\EloquentUserProvider;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Foundation\Auth\User;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;

abstract class EloquentUserProviderCacheTestCase extends DatabaseTestCase
{
    protected const string CACHE_STORE = 'auth-database-file';

    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set([
            'cache.serializable_classes' => false,
            'cache.stores.' . self::CACHE_STORE => [
                'driver' => 'file',
                'path' => $app->storagePath('framework/cache/data/auth-database'),
            ],
            'auth.providers.users' => [
                'driver' => 'eloquent',
                'model' => AuthDatabaseCacheUser::class,
                'cache' => [
                    'enabled' => true,
                    'store' => self::CACHE_STORE,
                ],
            ],
        ]);
    }

    protected function afterRefreshingDatabase(): void
    {
        Schema::create('auth_database_cache_users', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        try {
            $this->app->make('cache')->store(self::CACHE_STORE)->flush();
        } finally {
            parent::tearDown();
        }
    }

    public function testExplicitInvalidationFollowsCommitAndRollback(): void
    {
        $user = AuthDatabaseCacheUser::query()->create([
            'name' => 'Original',
            'email' => 'user@example.com',
            'password' => 'secret',
        ]);
        $provider = $this->cachedProvider();

        $this->assertSame('Original', $provider->retrieveById($user->getKey())?->name);

        DB::beginTransaction();

        try {
            AuthDatabaseCacheUser::query()->whereKey($user->getKey())->update(['name' => 'Rolled back']);
            $provider->clearUserCache($user->getKey());

            $this->assertSame('Original', $provider->retrieveById($user->getKey())?->name);

            DB::rollBack();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }

        $this->assertSame('Original', $provider->retrieveById($user->getKey())?->name);

        DB::beginTransaction();

        AuthDatabaseCacheUser::query()->whereKey($user->getKey())->update(['name' => 'Committed']);
        $provider->clearUserCache($user->getKey());

        $this->assertSame('Original', $provider->retrieveById($user->getKey())?->name);

        DB::commit();

        $this->assertSame('Committed', $provider->retrieveById($user->getKey())?->name);
    }

    public function testModelEventInvalidationFollowsCommitAndRollback(): void
    {
        $user = AuthDatabaseCacheUser::query()->create([
            'name' => 'Original',
            'email' => 'user@example.com',
            'password' => 'secret',
        ]);
        $provider = $this->cachedProvider();

        $this->assertSame('Original', $provider->retrieveById($user->getKey())?->name);

        DB::beginTransaction();

        try {
            $user->forceFill(['name' => 'Rolled back'])->save();

            $this->assertSame('Original', $provider->retrieveById($user->getKey())?->name);

            DB::rollBack();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }

        $this->assertSame('Original', $provider->retrieveById($user->getKey())?->name);

        $user = AuthDatabaseCacheUser::query()->findOrFail($user->getKey());

        DB::beginTransaction();

        $user->forceFill(['name' => 'Committed'])->save();

        $this->assertSame('Original', $provider->retrieveById($user->getKey())?->name);

        DB::commit();

        $this->assertSame('Committed', $provider->retrieveById($user->getKey())?->name);
    }

    protected function cachedProvider(): EloquentUserProvider
    {
        $provider = new EloquentUserProvider($this->app->make('hash'), AuthDatabaseCacheUser::class);
        $provider->enableCache(self::CACHE_STORE);

        return $provider;
    }
}

class AuthDatabaseCacheUser extends User
{
    protected ?string $table = 'auth_database_cache_users';

    protected array $guarded = [];
}
