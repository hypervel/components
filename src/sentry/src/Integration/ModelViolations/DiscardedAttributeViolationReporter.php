<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Integration\ModelViolations;

use Exception;
use Hypervel\Database\Eloquent\MassAssignmentException;
use Hypervel\Database\Eloquent\Model;

class DiscardedAttributeViolationReporter extends ModelViolationReporter
{
    protected function getViolationContext(Model $model, string $property): array
    {
        return [
            'attribute' => $property,
            'kind' => 'discarded_attribute',
        ];
    }

    protected function getViolationException(Model $model, string $property): Exception
    {
        return new MassAssignmentException(sprintf(
            'Add [%s] to fillable property to allow mass assignment on [%s].',
            $property,
            get_class($model)
        ));
    }
}
