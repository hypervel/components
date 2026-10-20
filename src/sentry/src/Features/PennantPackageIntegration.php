<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Features;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Pennant\Events\FeatureRetrieved;
use Hypervel\Pennant\Feature as Pennant;
use Sentry\SentrySdk;
use Sentry\State\Scope;

class PennantPackageIntegration extends Feature
{
    /**
     * Indicate if the feature is applicable to the current environment.
     */
    public function isApplicable(): bool
    {
        return class_exists(Pennant::class);
    }

    /**
     * Set up the feature in the environment.
     */
    public function onBoot(): void
    {
        // Until the application has booted, the current Sentry scope is the baseline that every
        // request copies, so flags checked while providers boot would appear on every request.
        $this->container->make(Application::class)->booted(function (): void {
            $this->container->make('events')->listen(FeatureRetrieved::class, [$this, 'handleFeatureRetrieved']);
        });
    }

    /**
     * Record the retrieved feature flag on the current scope.
     */
    public function handleFeatureRetrieved(FeatureRetrieved $feature): void
    {
        SentrySdk::getCurrentHub()->configureScope(function (Scope $scope) use ($feature) {
            // backslashes are not allowed in feature flag names, so we replace them with dots to allow class
            // based feature flags
            $featureName = str_replace('\\', '.', ltrim($feature->feature, '\\'));

            // The value of the feature is not always a bool (Rich Feature Values) but only bools are supported.
            // The feature is considered "active" if its value is not explicitly false following Pennant's logic.
            $scope->addFeatureFlag($featureName, $feature->value !== false);
        });
    }
}
