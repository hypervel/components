<?php

declare(strict_types=1);

namespace Hypervel\Permission;

use Composer\InstalledVersions;
use Hypervel\Cache\CacheManager;
use Hypervel\Container\Container;
use Hypervel\Contracts\Auth\Access\Gate as GateContract;
use Hypervel\Contracts\Auth\Factory as AuthFactory;
use Hypervel\Foundation\Console\AboutCommand;
use Hypervel\Permission\Commands\AssignRoleCommand;
use Hypervel\Permission\Commands\CacheResetCommand;
use Hypervel\Permission\Commands\CreatePermissionCommand;
use Hypervel\Permission\Commands\CreateRoleCommand;
use Hypervel\Permission\Commands\ShowCommand;
use Hypervel\Permission\Commands\UpgradeForTeamsCommand;
use Hypervel\Permission\Contracts\Permission as PermissionContract;
use Hypervel\Permission\Contracts\Role as RoleContract;
use Hypervel\Permission\Middleware\PermissionMiddleware;
use Hypervel\Permission\Middleware\RoleMiddleware;
use Hypervel\Permission\Middleware\RoleOrPermissionMiddleware;
use Hypervel\Routing\Route;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\ServiceProvider;
use Hypervel\View\Compilers\BladeCompiler;

use function Hypervel\Support\enum_value;

class PermissionServiceProvider extends ServiceProvider
{
    /**
     * Register any package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/permission.php', 'permission');

        $this->app->singleton(PermissionRegistrar::class, fn ($app) => new PermissionRegistrar(
            $app->make(CacheManager::class),
            $app->make('config'),
            $app,
        ));

        $this->registerModelBindings();

        $this->commands([
            AssignRoleCommand::class,
            CacheResetCommand::class,
            CreatePermissionCommand::class,
            CreateRoleCommand::class,
            ShowCommand::class,
            UpgradeForTeamsCommand::class,
        ]);
    }

    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        $this->registerPublishing();
        $this->registerMacroHelpers();
        $this->registerMiddleware();
        $this->registerBladeExtensions();
        $this->registerGateHook();
        $this->registerAbout();

        // Laravel Octane reset listeners are not ported. Hypervel stores transient team
        // state in CoroutineContext and keeps permission cache freshness in the cache layer.
    }

    /**
     * Wrap a Blade authorization check.
     */
    public static function bladeMethodWrapper(string $method, mixed $role, ?string $guard = null): bool
    {
        $authGuard = Container::getInstance()->make(AuthFactory::class)->guard(Guard::normalizeName($guard));

        return $authGuard->check() && $authGuard->user()->{$method}($role);
    }

    /**
     * Register package publishing.
     */
    protected function registerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__ . '/../config/permission.php' => config_path('permission.php'),
        ], 'permission-config');

        $this->publishesMigrations([
            __DIR__ . '/../database/migrations/2025_07_02_000000_create_permission_tables.php' => database_path('migrations/2025_07_02_000000_create_permission_tables.php'),
        ], 'permission-migrations');
    }

    /**
     * Register Blade directives.
     */
    protected function registerBladeExtensions(): void
    {
        $this->callAfterResolving('blade.compiler', function (BladeCompiler $bladeCompiler): void {
            $bladeMethodWrapper = '\\' . static::class . '::bladeMethodWrapper';

            // permission checks
            $bladeCompiler->if('haspermission', fn () => $bladeMethodWrapper('checkPermissionTo', ...func_get_args()));

            // role checks
            $bladeCompiler->if('role', fn () => $bladeMethodWrapper('hasRole', ...func_get_args()));
            $bladeCompiler->if('hasrole', fn () => $bladeMethodWrapper('hasRole', ...func_get_args()));
            $bladeCompiler->if('hasanyrole', fn () => $bladeMethodWrapper('hasAnyRole', ...func_get_args()));
            $bladeCompiler->if('hasallroles', fn () => $bladeMethodWrapper('hasAllRoles', ...func_get_args()));
            $bladeCompiler->if('hasexactroles', fn () => $bladeMethodWrapper('hasExactRoles', ...func_get_args()));
            $bladeCompiler->directive('endunlessrole', fn () => '<?php endif; ?>');
        });
    }

    /**
     * Register model contract bindings.
     */
    protected function registerModelBindings(): void
    {
        $this->app->bind(PermissionContract::class, fn ($app) => $app->make(
            $app->make('config')->string('permission.models.permission')
        ));

        $this->app->bind(RoleContract::class, fn ($app) => $app->make(
            $app->make('config')->string('permission.models.role')
        ));
    }

    /**
     * Register route macros.
     */
    protected function registerMacroHelpers(): void
    {
        Route::macro('role', function ($roles = []) {
            $roles = Arr::wrap($roles);
            $roles = array_map(fn ($role) => enum_value($role), $roles);

            /** @var Route $this */
            return $this->middleware('role:' . implode('|', $roles));
        });

        Route::macro('permission', function ($permissions = []) {
            $permissions = Arr::wrap($permissions);
            $permissions = array_map(fn ($permission) => enum_value($permission), $permissions);

            /** @var Route $this */
            return $this->middleware('permission:' . implode('|', $permissions));
        });

        Route::macro('roleOrPermission', function ($rolesOrPermissions = []) {
            $rolesOrPermissions = Arr::wrap($rolesOrPermissions);
            $rolesOrPermissions = array_map(fn ($item) => enum_value($item), $rolesOrPermissions);

            /** @var Route $this */
            return $this->middleware('role_or_permission:' . implode('|', $rolesOrPermissions));
        });
    }

    /**
     * Register middleware aliases.
     */
    protected function registerMiddleware(): void
    {
        $router = $this->app->make('router');

        $router->aliasMiddleware('role', RoleMiddleware::class);
        $router->aliasMiddleware('permission', PermissionMiddleware::class);
        $router->aliasMiddleware('role_or_permission', RoleOrPermissionMiddleware::class);
    }

    /**
     * Register the Gate permission hook.
     */
    protected function registerGateHook(): void
    {
        $this->callAfterResolving(GateContract::class, function (GateContract $gate): void {
            $config = $this->app->make('config');

            if (! $config->boolean('permission.register_permission_check_method', true)) {
                return;
            }

            $permissionRegistrar = $this->app->make(PermissionRegistrar::class);
            $permissionRegistrar->clearPermissionsCollection();
            $permissionRegistrar->registerPermissions($gate);
        });
    }

    /**
     * Register package information for the about command.
     */
    protected function registerAbout(): void
    {
        if (! class_exists(InstalledVersions::class) || ! class_exists(AboutCommand::class)) {
            return;
        }

        $features = [
            'Teams' => 'teams',
            'Wildcard Permissions' => 'enable_wildcard_permission',
            'Passport Client Credentials' => 'use_passport_client_credentials',
            'Forbidden Permissions' => null,
        ];

        $config = $this->app->make('config');

        AboutCommand::add('Hypervel Permissions', static function () use ($features, $config): array {
            $enabledFeatures = Collection::make($features)
                ->filter(fn (?string $feature): bool => $feature === null || $config->boolean("permission.{$feature}", false))
                ->keys();

            if (PermissionRegistrar::partitioningEnabled()) {
                $enabledFeatures->push('Row Partitioning');
            }

            return [
                'Features Enabled' => $enabledFeatures
                    ->whenEmpty(fn (Collection $collection) => $collection->push('Default'))
                    ->join(', '),
                'Version' => InstalledVersions::isInstalled('hypervel/permission')
                    ? InstalledVersions::getPrettyVersion('hypervel/permission')
                    : null,
            ];
        });
    }
}
