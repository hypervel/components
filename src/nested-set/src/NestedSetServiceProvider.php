<?php

declare(strict_types=1);

namespace Hypervel\NestedSet;

use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\ServiceProvider;

class NestedSetServiceProvider extends ServiceProvider
{
    /**
     * Register any package services.
     */
    public function register(): void
    {
        Blueprint::macro('nestedSet', function (array $scopes = []): void {
            NestedSet::columns($this, $scopes);
        });

        Blueprint::macro('integerNestedSet', function (array $scopes = []): void {
            NestedSet::integerColumns($this, $scopes);
        });

        Blueprint::macro('uuidNestedSet', function (array $scopes = []): void {
            NestedSet::uuidColumns($this, $scopes);
        });

        Blueprint::macro('ulidNestedSet', function (array $scopes = []): void {
            NestedSet::ulidColumns($this, $scopes);
        });

        Blueprint::macro('dropNestedSet', function (array $scopes = []): void {
            NestedSet::dropColumns($this, $scopes);
        });
    }
}
