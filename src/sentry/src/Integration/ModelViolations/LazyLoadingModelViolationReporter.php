<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Integration\ModelViolations;

use Exception;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\LazyLoadingViolationException;

class LazyLoadingModelViolationReporter extends ModelViolationReporter
{
    /**
     * Determine if the violation should be reported.
     */
    protected function shouldReport(Model $model, string $property): bool
    {
        // Hypervel uses these checks itself to not throw an exception if the model doesn't exist or was just created
        // See: https://github.com/laravel/framework/blob/438d02d3a891ab4d73ffea2c223b5d37947b5e93/src/Illuminate/Database/Eloquent/Concerns/HasAttributes.php#L559-L561
        if (! $model->exists || $model->wasRecentlyCreated) {
            return false;
        }

        return parent::shouldReport($model, $property);
    }

    /**
     * Get the context data for the violation.
     */
    protected function getViolationContext(Model $model, string $property): array
    {
        return [
            'relation' => $property,
            'kind' => 'lazy_loading',
        ];
    }

    /**
     * Get the exception representing the violation.
     */
    protected function getViolationException(Model $model, string $property): Exception
    {
        return new LazyLoadingViolationException($model, $property);
    }
}
