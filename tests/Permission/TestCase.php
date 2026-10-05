<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission;

use Hypervel\Auth\EloquentUserProvider;
use Hypervel\Cache\CacheManager;
use Hypervel\Cache\DatabaseStore;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Permission\Contracts\Permission as PermissionContract;
use Hypervel\Permission\Contracts\Role as RoleContract;
use Hypervel\Permission\Exceptions\UnauthorizedException;
use Hypervel\Permission\Guard;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Permission\PermissionServiceProvider;
use Hypervel\Support\Facades\Auth;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\TestCase as TestbenchTestCase;
use Hypervel\Tests\Permission\Fixtures\Models\Admin;
use Hypervel\Tests\Permission\Fixtures\Models\Client;
use Hypervel\Tests\Permission\Fixtures\Models\Permission;
use Hypervel\Tests\Permission\Fixtures\Models\Role;
use Hypervel\Tests\Permission\Fixtures\Models\Team;
use Hypervel\Tests\Permission\Fixtures\Models\User;
use Hypervel\Tests\Permission\Fixtures\PassportGuard;

use function Hypervel\Testbench\default_migration_path;

abstract class TestCase extends TestbenchTestCase
{
    use RefreshDatabase;

    protected bool $migrateRefresh = true;

    protected User $testUser;

    protected Admin $testAdmin;

    protected \Hypervel\Permission\Models\Role $testUserRole;

    protected \Hypervel\Permission\Models\Role $testAdminRole;

    protected \Hypervel\Permission\Models\Permission $testUserPermission;

    protected \Hypervel\Permission\Models\Permission $testAdminPermission;

    protected Client $testClient;

    protected \Hypervel\Permission\Models\Permission $testClientPermission;

    protected \Hypervel\Permission\Models\Role $testClientRole;

    /**
     * Get package providers.
     * @param mixed $app
     */
    protected function getPackageProviders($app): array
    {
        return [
            PermissionServiceProvider::class,
        ];
    }

    /**
     * Set up the package environment.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        Model::preventLazyLoading();

        $app->make('config')->set([
            'database.default' => 'testing',
            'permission.register_permission_check_method' => true,
            'permission.column_names.model_morph_key' => 'model_test_id',
            'permission.column_names.team_foreign_key' => 'team_test_id',
            'permission.column_names.role_pivot_key' => 'role_test_id',
            'permission.column_names.permission_pivot_key' => 'permission_test_id',
            'auth.guards.web' => [
                'driver' => 'session',
                'provider' => 'users',
                'passwords' => 'users',
                'password_timeout' => null,
                'remember' => null,
            ],
            'auth.guards.api' => [
                'driver' => 'session',
                'provider' => 'users',
                'passwords' => null,
                'password_timeout' => null,
                'remember' => null,
            ],
            'auth.guards.admin' => [
                'driver' => 'session',
                'provider' => 'admins',
                'passwords' => null,
                'password_timeout' => null,
                'remember' => null,
            ],
            'auth.providers.users' => [
                'driver' => 'eloquent',
                'model' => User::class,
                'cache' => [
                    'enabled' => false,
                    'store' => null,
                    'ttl' => 300,
                    'prefix' => EloquentUserProvider::DEFAULT_CACHE_PREFIX,
                    'tags' => null,
                ],
            ],
            'auth.providers.admins' => [
                'driver' => 'eloquent',
                'model' => Admin::class,
                'cache' => [
                    'enabled' => false,
                    'store' => null,
                    'ttl' => 300,
                    'prefix' => EloquentUserProvider::DEFAULT_CACHE_PREFIX,
                    'tags' => null,
                ],
            ],
            'view.paths' => [__DIR__ . '/Fixtures/views'],
            'cache.prefix' => 'permission_tests',
            // Pruning expired database cache locks would add a random query to counted queries.
            'cache.stores.database.lock_lottery' => [0, 100],
        ]);
    }

    /**
     * Get the migrations to run for the test.
     */
    protected function migrateFreshUsing(): array
    {
        $paths = [dirname(__DIR__, 2) . '/src/permission/database/migrations'];

        // The permission migration clears its cache keys, so a database store needs its tables first.
        if ($this->app->make(PermissionRegistrar::class)->getCacheStore() instanceof DatabaseStore) {
            $paths[] = default_migration_path() . '/0001_01_01_000003_testbench_create_cache_table.php';
            $paths[] = default_migration_path() . '/0001_01_01_000004_testbench_create_cache_locks_table.php';
        }

        return [
            '--seed' => $this->shouldSeed(),
            '--database' => $this->getRefreshConnection(),
            '--realpath' => true,
            '--path' => $paths,
        ];
    }

    /**
     * Seed the database after refreshing it.
     */
    protected function afterRefreshingDatabase(): void
    {
        $this->createFixtureTables();
        $this->flushPermissionState();
        $this->setUpBaseTestPermissions();
        $this->setUpRoutes();
    }

    /**
     * Create fixture tables.
     */
    protected function createFixtureTables(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('email');
            $table->softDeletes();
        });

        Schema::create('admins', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('email');
        });

        Schema::create('clients', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });

        Schema::create('content', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('content');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('teams', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
    }

    /**
     * Set up initial roles and permissions.
     */
    protected function setUpBaseTestPermissions(): void
    {
        $this->testUser = User::create(['email' => 'test@user.com']);
        $this->testAdmin = Admin::create(['email' => 'admin@user.com']);

        $this->testUserRole = $this->app->make(RoleContract::class)->create(['name' => 'testRole']);
        $this->app->make(RoleContract::class)->create(['name' => 'testRole2']);
        $this->testAdminRole = $this->app->make(RoleContract::class)->create(['name' => 'testAdminRole', 'guard_name' => 'admin']);
        $this->testUserPermission = $this->app->make(PermissionContract::class)->create(['name' => 'edit-articles']);
        $this->app->make(PermissionContract::class)->create(['name' => 'edit-news']);
        $this->app->make(PermissionContract::class)->create(['name' => 'edit-blog']);
        $this->testAdminPermission = $this->app->make(PermissionContract::class)->create([
            'name' => 'admin-permission',
            'guard_name' => 'admin',
        ]);
        $this->app->make(PermissionContract::class)->create(['name' => 'Edit News']);
    }

    /**
     * Set up Passport-style client fixtures.
     */
    protected function setUpPassport(): void
    {
        $this->app->make('config')->set([
            'permission.use_passport_client_credentials' => true,
            'auth.guards.api' => ['driver' => 'passport', 'provider' => 'users'],
        ]);

        $this->testClient = Client::create(['name' => 'Test']);
        $this->testClientRole = $this->app->make(RoleContract::class)->create(['name' => 'clientRole', 'guard_name' => 'api']);
        $this->testClientPermission = $this->app->make(PermissionContract::class)->create(['name' => 'edit-posts', 'guard_name' => 'api']);
    }

    /**
     * Authenticate the given client through the Passport guard.
     *
     * Hypervel has no Passport package, so this stands in for Passport::actingAsClient().
     */
    protected function actingAsClient(Client $client): void
    {
        Auth::extend('passport', fn (): PassportGuard => new PassportGuard($client));
        Auth::forgetGuards();
    }

    /**
     * Set up team-aware permissions.
     */
    protected function setUpTeams(): void
    {
        $this->app->make('config')->set('permission.teams', true);
        $this->flushPermissionState();
        setPermissionsTeamId(1);
    }

    /**
     * Enable teams before the permission tables are migrated.
     *
     * Upstream's migration always adds the roles team column under its `permission.testing`
     * flag. Hypervel's migration adds team columns only when teams are enabled.
     */
    protected function usesTeams(ApplicationContract $app): void
    {
        $app->make('config')->set('permission.teams', true);
    }

    /**
     * Set up custom role and permission models.
     */
    protected function setUpCustomModels(): void
    {
        $this->app->make('config')->set([
            'permission.models.permission' => Permission::class,
            'permission.models.role' => Role::class,
        ]);

        $this->recreateCustomPermissionTables();
        $this->flushPermissionState();
        $this->setUpBaseTestPermissions();
    }

    /**
     * Set up nested role hierarchy tables.
     */
    protected function setUpRoleNesting(): void
    {
        $this->setUpCustomModels();

        Schema::create(Role::HIERARCHY_TABLE, function (Blueprint $table): void {
            $table->id();
            $table->uuid('parent_id');
            $table->uuid('child_id');
            $table->foreign('parent_id')->references('role_test_id')->on('roles');
            $table->foreign('child_id')->references('role_test_id')->on('roles');
        });
    }

    /**
     * Use a user model for the default auth provider.
     *
     * @param class-string<Model> $model
     */
    protected function useAuthUserModel(string $model): void
    {
        $this->app->make('config')->set('auth.providers.users.model', $model);

        // Guard caches provider models for the worker lifetime.
        Guard::flushState();
    }

    /**
     * Reload permission cache state.
     */
    protected function reloadPermissions(): void
    {
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Determine whether the permission cache uses the database store.
     */
    protected function usesDatabaseCacheStore(): bool
    {
        return $this->app->make(PermissionRegistrar::class)->getCacheStore() instanceof DatabaseStore;
    }

    /**
     * Create the database cache table.
     */
    protected function createCacheTable(): void
    {
        Schema::create('cache', function (Blueprint $table): void {
            $table->string('key')->unique();
            $table->text('value');
            $table->integer('expiration');
        });
    }

    /**
     * Define test routes.
     */
    protected function setUpRoutes(): void
    {
        Route::middleware('auth:api')->get('/check-api-guard-permission', function (Request $request): array {
            return [
                'status' => $request->user()->hasPermissionTo('do_that'),
            ];
        });
    }

    /**
     * Run middleware and return its response code.
     */
    protected function runMiddleware(object $middleware, mixed $permission, ?string $guard = null, bool $client = false): int
    {
        $request = new Request;

        if ($client) {
            $request->headers->set('Authorization', 'Bearer ' . str()->random(30));
        }

        try {
            return $middleware->handle($request, function (): Response {
                return (new Response)->setContent('<html></html>');
            }, $permission, $guard)->getStatusCode();
        } catch (UnauthorizedException $exception) {
            return $exception->getStatusCode();
        }
    }

    /**
     * Get the last route middleware from the router.
     */
    protected function getLastRouteMiddlewareFromRouter(mixed $router): array
    {
        return last($router->getRoutes()->get())->middleware();
    }

    /**
     * Get the router.
     */
    protected function getRouter(): mixed
    {
        return $this->app->make('router');
    }

    /**
     * Get a route response callback.
     */
    protected function getRouteResponse(): callable
    {
        return function (): Response {
            return (new Response)->setContent('<html></html>');
        };
    }

    /**
     * Flush permission cache and singleton state.
     */
    protected function flushPermissionState(): void
    {
        Guard::flushState();
        $cacheStore = config()->string('permission.cache.store', 'default');

        $this->app->make(CacheManager::class)
            ->memo($cacheStore === 'default' ? null : $cacheStore)
            ->clear();
        $this->app->forgetInstance(PermissionRegistrar::class);

        // The store flush does not clear registrar runtime memos held in CoroutineContext.
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Recreate permission tables for custom UUID role and permission models.
     */
    private function recreateCustomPermissionTables(): void
    {
        $tableNames = (array) $this->app->make('config')->get('permission.table_names');
        $columnNames = (array) $this->app->make('config')->get('permission.column_names');
        $pivotRole = $columnNames['role_pivot_key'];
        $pivotPermission = $columnNames['permission_pivot_key'];
        $modelMorphKey = $columnNames['model_morph_key'];

        Schema::dropIfExists($tableNames['role_has_permissions']);
        Schema::dropIfExists($tableNames['model_has_roles']);
        Schema::dropIfExists($tableNames['model_has_permissions']);
        Schema::dropIfExists($tableNames['roles']);
        Schema::dropIfExists($tableNames['permissions']);

        Schema::create($tableNames['permissions'], static function (Blueprint $table): void {
            $table->uuid('permission_test_id')->primary();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['name', 'guard_name']);
        });

        Schema::create($tableNames['roles'], static function (Blueprint $table): void {
            $table->uuid('role_test_id')->primary();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['name', 'guard_name']);
        });

        Schema::create($tableNames['model_has_permissions'], static function (Blueprint $table) use ($modelMorphKey, $pivotPermission, $tableNames): void {
            $table->uuid($pivotPermission);
            $table->string('model_type');
            $table->unsignedBigInteger($modelMorphKey);
            $table->boolean('is_denied')->default(false);
            $table->index([$modelMorphKey, 'model_type'], 'model_has_permissions_model_id_model_type_index');

            $table->foreign($pivotPermission)
                ->references('permission_test_id')
                ->on($tableNames['permissions'])
                ->cascadeOnDelete();

            $table->primary([$pivotPermission, $modelMorphKey, 'model_type'], 'model_has_permissions_permission_model_type_primary');
        });

        Schema::create($tableNames['model_has_roles'], static function (Blueprint $table) use ($modelMorphKey, $pivotRole, $tableNames): void {
            $table->uuid($pivotRole);
            $table->string('model_type');
            $table->unsignedBigInteger($modelMorphKey);
            $table->index([$modelMorphKey, 'model_type'], 'model_has_roles_model_id_model_type_index');

            $table->foreign($pivotRole)
                ->references('role_test_id')
                ->on($tableNames['roles'])
                ->cascadeOnDelete();

            $table->primary([$pivotRole, $modelMorphKey, 'model_type'], 'model_has_roles_role_model_type_primary');
        });

        Schema::create($tableNames['role_has_permissions'], static function (Blueprint $table) use ($pivotPermission, $pivotRole, $tableNames): void {
            $table->uuid($pivotPermission);
            $table->uuid($pivotRole);
            $table->boolean('is_denied')->default(false);

            $table->foreign($pivotPermission)
                ->references('permission_test_id')
                ->on($tableNames['permissions'])
                ->cascadeOnDelete();

            $table->foreign($pivotRole)
                ->references('role_test_id')
                ->on($tableNames['roles'])
                ->cascadeOnDelete();

            $table->primary([$pivotPermission, $pivotRole], 'role_has_permissions_permission_id_role_id_primary');
        });
    }
}
