<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Integration\ModelViolations;

use Exception;
use Hypervel\Database\Eloquent\MissingAttributeException;
use Hypervel\Database\Eloquent\Model;

class MissingAttributeModelViolationReporter extends ModelViolationReporter
{
    /**
     * Get the context data for the violation.
     */
    protected function getViolationContext(Model $model, string $property): array
    {
        return [
            'attribute' => $property,
            'kind' => 'missing_attribute',
        ];
    }

    /**
     * Get the exception representing the violation.
     */
    protected function getViolationException(Model $model, string $property): Exception
    {
        return new MissingAttributeException($model, $property);
    }
}
