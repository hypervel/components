<?php

declare(strict_types=1);

namespace RuntimeInputs\Providers;

use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\Support\ServiceProvider;
use Hypervel\Support\Str;
use RuntimeInputs\FacadeRoot;
use RuntimeInputs\Models\Post;
use RuntimeInputs\Registrar;
use RuntimeInputs\StrMixin;

class FixtureServiceProvider extends ServiceProvider
{
    /**
     * Register the fixture bindings.
     */
    public function register(): void
    {
        Registrar::register($this->app);

        // Bound lazily, so the root class loads during analysis, after the boot.
        $this->app->bind('fixture.root', static fn (): FacadeRoot => new FacadeRoot);
    }

    /**
     * Register the fixture macros, morph map and view namespace.
     */
    public function boot(): void
    {
        Str::macro('fixtureMacro', fn (): int => 1);
        Str::mixin(new StrMixin);
        Relation::morphMap(['post' => Post::class]);
        $this->loadViewsFrom(dirname(__DIR__, 2) . '/resources/fixture-views', 'fixture');
    }
}
