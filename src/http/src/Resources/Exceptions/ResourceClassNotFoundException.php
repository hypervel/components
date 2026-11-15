<?php

declare(strict_types=1);

namespace Hypervel\Http\Resources\Exceptions;

use LogicException;

class ResourceClassNotFoundException extends LogicException
{
    protected object $model;

    /**
     * Create an exception for a model without a discoverable resource class.
     */
    public static function forModel(object $model): static
    {
        $exception = new static(sprintf('Failed to find resource class for model [%s].', $model::class));
        $exception->model = $model;

        return $exception;
    }

    /**
     * Get the model whose resource class could not be found.
     */
    public function getModel(): object
    {
        return $this->model;
    }
}
