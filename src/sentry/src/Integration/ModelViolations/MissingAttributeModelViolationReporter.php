<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Integration\ModelViolations;

use Exception;
use Hypervel\Database\Eloquent\MissingAttributeException;
use Hypervel\Database\Eloquent\Model;

class MissingAttributeModelViolationReporter extends ModelViolationReporter
{
    protected function getViolationContext(Model $model, string $property): array
    {
        return [
            'attribute' => $property,
            'kind' => 'missing_attribute',
        ];
    }

    protected function getViolationException(Model $model, string $property): Exception
    {
        return new MissingAttributeException($model, $property);
    }
}
