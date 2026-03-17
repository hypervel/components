<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Support\Providers;

use Hypervel\Support\Facades\Gate;
use Hypervel\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected array $policies = [];

    /**
     * Register the application's policies.
     */
    public function register(): void
    {
        $this->booting(function () {
            $this->registerPolicies();
        });
    }

    /**
     * Register the application's policies.
     */
    public function registerPolicies(): void
    {
        foreach ($this->policies() as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }

    /**
     * Get the policies defined on the provider.
     *
     * @return array<class-string, class-string>
     */
    public function policies(): array
    {
        return $this->policies;
    }
}
