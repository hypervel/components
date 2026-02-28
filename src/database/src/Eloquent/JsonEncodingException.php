<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent;

use RuntimeException;

class JsonEncodingException extends RuntimeException
{
    /**
     * Create a new JSON encoding exception for the model.
     */
    public static function forModel(Model $model, string $message): static
    {
        return new static('Error encoding model [' . get_class($model) . '] with ID [' . $model->getKey() . '] to JSON: ' . $message);
    }

    /**
     * Create a new JSON encoding exception for the resource.
     *
     * @param \Hypervel\Http\Resources\Json\JsonResource $resource
     */
    public static function forResource(object $resource, string $message): static
    {
        $model = $resource->resource;

        return new static('Error encoding resource [' . get_class($resource) . '] with model [' . get_class($model) . '] with ID [' . $model->getKey() . '] to JSON: ' . $message);
    }

    /**
     * Create a new JSON encoding exception for an attribute.
     */
    public static function forAttribute(Model $model, mixed $key, string $message): static
    {
        $class = get_class($model);

        return new static("Unable to encode attribute [{$key}] for model [{$class}] to JSON: {$message}.");
    }
}
