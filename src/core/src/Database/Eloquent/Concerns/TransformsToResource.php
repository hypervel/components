<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Concerns;

use Hyperf\Stringable\Str;
use Hypervel\Database\Eloquent\Attributes\UseResource;
use Hypervel\Http\Resources\Json\JsonResource;
use LogicException;
use ReflectionClass;

/**
 * Provides the ability to transform a model to a JSON resource.
 */
trait TransformsToResource
{
    /**
     * Create a new resource object for the given resource.
     *
     * @param null|class-string<\Hypervel\Http\Resources\Json\JsonResource> $resourceClass
     */
    public function toResource(?string $resourceClass = null): JsonResource
    {
        if ($resourceClass === null) {
            return $this->guessResource();
        }

        return $resourceClass::make($this);
    }

    /**
     * Guess the resource class for the model.
     */
    protected function guessResource(): JsonResource
    {
        $resourceClass = $this->resolveResourceFromAttribute(static::class);

        if ($resourceClass !== null && class_exists($resourceClass)) {
            return $resourceClass::make($this);
        }

        foreach (static::guessResourceName() as $resourceClass) {
            /** @phpstan-ignore-next-line function.alreadyNarrowedType */
            if (is_string($resourceClass) && class_exists($resourceClass)) {
                return $resourceClass::make($this);
            }
        }

        throw new LogicException(sprintf('Failed to find resource class for model [%s].', get_class($this)));
    }

    /**
     * Guess the resource class name for the model.
     *
     * @return array<int, class-string<\Hypervel\Http\Resources\Json\JsonResource>>
     */
    public static function guessResourceName(): array
    {
        $modelClass = static::class;

        if (! Str::contains($modelClass, '\Models\\')) {
            return [];
        }

        $relativeNamespace = Str::after($modelClass, '\Models\\');

        $relativeNamespace = Str::contains($relativeNamespace, '\\')
            ? Str::before($relativeNamespace, '\\' . class_basename($modelClass))
            : '';

        $potentialResource = sprintf(
            '%s\Http\Resources\%s%s',
            Str::before($modelClass, '\Models'),
            strlen($relativeNamespace) > 0 ? $relativeNamespace . '\\' : '',
            class_basename($modelClass)
        );

        return [$potentialResource . 'Resource', $potentialResource];
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
}
