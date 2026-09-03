<?php

declare(strict_types=1);

namespace Hypervel\Data\Support;

use Hypervel\Contracts\Pagination\CursorPaginator;
use Hypervel\Contracts\Pagination\Paginator;
use Hypervel\Data\Casts\Cast;
use Hypervel\Data\Contracts\BaseData;
use Hypervel\Data\DataCollection;
use Hypervel\Data\Transformers\Transformer;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Enumerable;
use Hypervel\Support\LazyCollection;
use Hypervel\Support\StrCache;
use ReflectionAttribute;
use ReflectionProperty;

class DataProperty
{
    /**
     * Create a new data property definition.
     *
     * @param class-string $className
     * @param null|ReflectionAttribute<object> $autoLazy
     * @param null|ReflectionAttribute<object> $cast
     * @param null|ReflectionAttribute<object> $transformer
     * @param null|non-empty-list<array-key> $inputMappedPath
     * @param list<class-string<Cast>> $configuredCasts
     * @param list<class-string<Transformer>> $configuredTransformers
     */
    public function __construct(
        public readonly string $name,
        public readonly string $className,
        public readonly DataPropertyType $type,
        public readonly bool $validate,
        public readonly bool $computed,
        public readonly bool $hidden,
        public readonly bool $isPromoted,
        public readonly bool $isConstructorParameter,
        public readonly bool $isReadonly,
        public readonly bool $hasGetHook,
        public readonly bool $morphable,
        public readonly bool $loadRelation,
        public readonly ?ReflectionAttribute $autoLazy,
        public readonly bool $hasDefaultValue,
        public readonly ?ReflectionAttribute $cast,
        public readonly ?ReflectionAttribute $transformer,
        public readonly string|int|null $inputMappedName,
        public readonly ?array $inputMappedPath,
        public readonly string|int|null $outputMappedName,
        public readonly array $configuredCasts,
        public readonly array $configuredTransformers,
        public readonly DataAttributesCollection $attributes,
        public readonly ReflectionProperty $reflection,
    ) {
    }

    /**
     * Get the compiled input path for a selected wire key.
     *
     * The returned list is shared immutable worker metadata and must not be mutated in place.
     *
     * @return non-empty-list<array-key>
     */
    public function inputPath(string|int $wireKey): array
    {
        return $this->inputMappedPath !== null && $wireKey === $this->inputMappedName
            ? $this->inputMappedPath
            : [$wireKey];
    }

    /**
     * Determine if a supplied value is a finished declared Data value.
     */
    public function isFinishedValue(mixed $value): bool
    {
        if ($value instanceof BaseData) {
            return $this->type->acceptsValue($value);
        }

        $type = $this->type->getDataCollectableType();

        if ($type === null
            || $type->dataClass === null
            || ! $type->acceptsValue($value)
        ) {
            return false;
        }

        if ($value instanceof DataCollection) {
            return is_a($value->getDataClass(), $type->dataClass, true);
        }

        if ($value instanceof LazyCollection) {
            return false;
        }

        $items = match (true) {
            $value instanceof Enumerable => $value->all(),
            $value instanceof CursorPaginator, $value instanceof Paginator => $value->items(),
            default => null,
        };

        if ($items === null) {
            return false;
        }

        foreach ($items as $item) {
            if (! $item instanceof $type->dataClass) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve the Eloquent relation selected by this property.
     */
    public function resolveModelRelation(Model $model): ?string
    {
        if (! $this->loadRelation) {
            return null;
        }

        $name = $model::$snakeAttributes ? StrCache::snake($this->name) : $this->name;
        $camelName = StrCache::camel($name);

        return match (true) {
            $model->isRelation($name) => $name,
            $model->isRelation($camelName) => $camelName,
            default => null,
        };
    }
}
