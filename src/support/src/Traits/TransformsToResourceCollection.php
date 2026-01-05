<?php

declare(strict_types=1);

namespace Hypervel\Support\Traits;

use Hypervel\Database\Eloquent\Attributes\UseResource;
use Hypervel\Database\Eloquent\Attributes\UseResourceCollection;
use Hypervel\Http\Resources\Json\ResourceCollection;
use LogicException;
use ReflectionClass;
use Throwable;

/**
 * Provides the ability to transform a collection to a resource collection.
 */
trait TransformsToResourceCollection
{
    /**
     * Create a new resource collection instance for the given resource.
     *
     * @param null|class-string<\Hypervel\Http\Resources\Json\JsonResource> $resourceClass
     * @throws Throwable
     */
    public function toResourceCollection(?string $resourceClass = null): ResourceCollection
    {
        if ($resourceClass === null) {
            return $this->guessResourceCollection();
        }

        return $resourceClass::collection($this);
    }

    /**
     * Guess the resource collection for the items.
     *
     * @throws Throwable
     */
    protected function guessResourceCollection(): ResourceCollection
    {
        if ($this->isEmpty()) {
            return new ResourceCollection($this);
        }

        $model = $this->items[0] ?? null;

        throw_unless(is_object($model), LogicException::class, 'Resource collection guesser expects the collection to contain objects.');

        /** @var class-string $className */
        $className = get_class($model);

        throw_unless(
            method_exists($className, 'guessResourceName'),
            LogicException::class,
            sprintf('Expected class %s to implement guessResourceName method. Make sure the model uses the TransformsToResource trait.', $className)
        );

        $useResourceCollection = $this->resolveResourceCollectionFromAttribute($className);

        if ($useResourceCollection !== null && class_exists($useResourceCollection)) {
            return new $useResourceCollection($this);
        }

        $useResource = $this->resolveResourceFromAttribute($className);

        if ($useResource !== null && class_exists($useResource)) {
            return $useResource::collection($this);
        }

        $resourceClasses = $className::guessResourceName();

        foreach ($resourceClasses as $resourceClass) {
            $resourceCollection = $resourceClass . 'Collection';
            if (class_exists($resourceCollection)) {
                return new $resourceCollection($this);
            }
        }

        foreach ($resourceClasses as $resourceClass) {
            if (is_string($resourceClass) && class_exists($resourceClass)) {
                return $resourceClass::collection($this);
            }
        }

        throw new LogicException(sprintf('Failed to find resource class for model [%s].', $className));
    }

    /**
     * Get the resource class from the UseResource attribute.
     *
     * @param class-string $class
     * @return null|class-string<\Hypervel\Http\Resources\Json\JsonResource>
     */
    protected function resolveResourceFromAttribute(string $class): ?string
    {
        if (! class_exists($class)) {
            return null;
        }

        $attributes = (new ReflectionClass($class))->getAttributes(UseResource::class);

        return $attributes !== []
            ? $attributes[0]->newInstance()->class
            : null;
    }

    /**
     * Get the resource collection class from the UseResourceCollection attribute.
     *
     * @param class-string $class
     * @return null|class-string<\Hypervel\Http\Resources\Json\ResourceCollection>
     */
    protected function resolveResourceCollectionFromAttribute(string $class): ?string
    {
        if (! class_exists($class)) {
            return null;
        }

        $attributes = (new ReflectionClass($class))->getAttributes(UseResourceCollection::class);

        return $attributes !== []
            ? $attributes[0]->newInstance()->class
            : null;
    }
}
