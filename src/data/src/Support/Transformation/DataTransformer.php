<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Transformation;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Data\Attributes\WithCastAndTransformer;
use Hypervel\Data\Attributes\WithTransformer;
use Hypervel\Data\Contracts\AppendableData;
use Hypervel\Data\Contracts\BaseData;
use Hypervel\Data\Contracts\BaseDataCollectable;
use Hypervel\Data\Contracts\IncludeableData;
use Hypervel\Data\Contracts\TransformableData;
use Hypervel\Data\Contracts\WrappableData;
use Hypervel\Data\DataCollection;
use Hypervel\Data\Exceptions\MaxTransformationDepthReached;
use Hypervel\Data\Lazy;
use Hypervel\Data\Optional;
use Hypervel\Data\Support\DataClass;
use Hypervel\Data\Support\DataClassRepository;
use Hypervel\Data\Support\DataConfig;
use Hypervel\Data\Support\DataProperty;
use Hypervel\Data\Support\Lazy\DefaultLazy;
use Hypervel\Data\Support\Types\Type;
use Hypervel\Data\Support\Wrapping\WrapExecutionType;
use Hypervel\Data\Transformers\Transformer;

class DataTransformer
{
    protected readonly ?DateTimeZone $dateTimezone;

    /**
     * Create a data transformer.
     */
    public function __construct(
        protected readonly Container $container,
        protected readonly DataClassRepository $dataClasses,
        protected readonly DataConfig $config,
    ) {
        $this->dateTimezone = $config->dateTimezone === null
            ? null
            : new DateTimeZone($config->dateTimezone);
    }

    /**
     * Transform one data object through the fixed metadata path.
     */
    public function transform(
        (BaseData&TransformableData)|(BaseDataCollectable&TransformableData) $data,
        TransformationContext $context,
    ): array {
        $extensions = [];

        return $data instanceof BaseDataCollectable
            ? $this->transformCollectable($data, $context, $extensions)
            : $this->transformData($data, $context, $extensions);
    }

    /**
     * Transform a nested data object within the current root operation.
     *
     * @param array<string, object> $extensions
     */
    protected function transformData(
        BaseData&TransformableData $data,
        TransformationContext $context,
        array &$extensions,
    ): array {
        if ($context->maxDepth !== null && $context->depth >= $context->maxDepth) {
            throw MaxTransformationDepthReached::create($context->maxDepth);
        }

        $dataClass = $this->dataClasses->get($data::class);
        $values = get_object_vars($data);

        if ($dataClass->plainTransform
            && ! $context->hasPartials()
            && $context->transformers === []
        ) {
            return $this->finalizeTransformation(
                $data,
                $dataClass,
                $context,
                $this->transformPlain($data, $dataClass, $values),
            );
        }

        $transformed = [];

        foreach ($dataClass->properties as $property) {
            if ($property->hidden
                || $context->except?->selects($property->name)
                || ($context->only !== null
                    && $context->only->children !== []
                    && ! isset($context->only->children[$property->name]))
            ) {
                continue;
            }

            if ($property->isVirtual) {
                $value = $data->{$property->name};
            } elseif (array_key_exists($property->name, $values)) {
                $value = $values[$property->name];
            } else {
                continue;
            }

            if ($value instanceof Optional) {
                continue;
            }

            if ($value instanceof Lazy) {
                if (! $this->includesLazy($value, $property, $context)) {
                    continue;
                }

                $value = $value->resolve();
            }

            $value = $this->transformPropertyValue(
                $property,
                $value,
                $context,
                $extensions,
            );

            $name = $context->mapPropertyNames && $property->outputMappedName !== null
                ? $property->outputMappedName
                : $property->name;

            $transformed[$name] = $value;
        }

        return $this->finalizeTransformation($data, $dataClass, $context, $transformed);
    }

    /**
     * Transform a data collection within the current root operation.
     *
     * @param array<string, object> $extensions
     */
    protected function transformCollectable(
        BaseDataCollectable&TransformableData $data,
        TransformationContext $context,
        array &$extensions,
    ): array {
        if ($context->maxDepth !== null && $context->depth >= $context->maxDepth) {
            throw MaxTransformationDepthReached::create($context->maxDepth);
        }

        $transformed = [];

        foreach ($this->collectableItems($data) as $key => $item) {
            if (! $context->transformValues) {
                if ($context->hasPartials() && $item instanceof IncludeableData) {
                    $item->getPartialsDefinition()->addResolved($context->partialDefinitions);
                }

                $transformed[$key] = $item;

                continue;
            }

            $itemContext = $context->withWrapExecutionType(
                $this->resolveWrapExecutionType($item, $context),
            );
            $transformed[$key] = $this->transformData(
                $item,
                $this->mergeInstancePartials($item, $itemContext),
                $extensions,
            );
        }

        return $data instanceof WrappableData && $context->wrapExecutionType->shouldExecute()
            ? $data->getWrap()->wrap($transformed, $this->config->wrap)
            : $transformed;
    }

    /**
     * Get collection items without triggering public transformation behavior.
     *
     * @return iterable<array-key, BaseData>
     */
    protected function collectableItems(BaseDataCollectable $data): iterable
    {
        return $data instanceof DataCollection
            ? $data->toCollection()
            : $data;
    }

    /**
     * Apply wrapping and resolved top-level data.
     */
    protected function finalizeTransformation(
        BaseData $data,
        DataClass $dataClass,
        TransformationContext $context,
        array $transformed,
    ): array {
        if ($dataClass->wrappable && $context->wrapExecutionType->shouldExecute()) {
            /** @var WrappableData $data */
            $transformed = $data->getWrap()->wrap($transformed, $this->config->wrap);
        }

        if (! $dataClass->appendable) {
            return $transformed;
        }

        /** @var AppendableData $data */
        $additional = $data->getAdditionalData();

        return $additional === []
            ? $transformed
            : array_merge($transformed, $additional);
    }

    /**
     * Copy values for metadata proven to need no property transformation.
     *
     * @param array<string, mixed> $values
     * @return array<array-key, mixed>
     */
    protected function transformPlain(BaseData $data, DataClass $dataClass, array $values): array
    {
        $transformed = [];

        foreach ($dataClass->properties as $property) {
            if ($property->isVirtual) {
                $transformed[$property->name] = $data->{$property->name};
            } elseif (array_key_exists($property->name, $values)) {
                $transformed[$property->name] = $values[$property->name];
            }
        }

        return $transformed;
    }

    /**
     * Determine if a lazy property is visible for this transformation.
     */
    protected function includesLazy(
        Lazy $lazy,
        DataProperty $property,
        TransformationContext $context,
    ): bool {
        if (! $lazy instanceof DefaultLazy) {
            return $lazy->shouldBeIncluded() === true;
        }

        if ($context->exclude?->selects($property->name)) {
            return false;
        }

        return $lazy->isDefaultIncluded()
            || ($context->include?->contains($property->name) ?? false);
    }

    /**
     * Transform one visible property value.
     *
     * @param array<string, object> $extensions
     */
    protected function transformPropertyValue(
        DataProperty $property,
        mixed $value,
        TransformationContext $context,
        array &$extensions,
    ): mixed {
        if ($value === null) {
            return null;
        }

        if ($context->transformValues) {
            $transformer = $this->propertyTransformer($property, $value, $context, $extensions);

            if ($transformer !== null) {
                return $transformer->transform($property, $value, $context);
            }
        }

        if ($value instanceof BaseData || $value instanceof BaseDataCollectable) {
            if (! $context->transformValues) {
                $this->propagatePartials($value, $context, $property->name);

                return $value;
            }

            $nestedContext = $context->child(
                $property->name,
                $this->resolveWrapExecutionType($value, $context),
            );

            if ($value instanceof BaseData) {
                return $this->transformData(
                    $value,
                    $this->mergeInstancePartials($value, $nestedContext),
                    $extensions,
                );
            }

            return $this->transformCollectable(
                $value,
                $this->mergeInstancePartials($value, $nestedContext),
                $extensions,
            );
        }

        $iterableType = $this->iterableTypeForValue($property, $value);

        if ($iterableType !== null) {
            if (! $context->transformValues) {
                $this->propagateIterablePartials($value, $context, $property->name);

                return $value;
            }

            return $this->transformIterable(
                $property,
                $value,
                $iterableType,
                $context->child($property->name),
                $extensions,
            );
        }

        if (is_array($value)) {
            return $this->filterArray(
                $value,
                $context->only?->child($property->name),
                $context->except?->child($property->name),
            );
        }

        if (! $context->transformValues) {
            return $value;
        }

        return $this->transformBuiltIn($value);
    }

    /**
     * Resolve wrapping behavior for a nested transformable value.
     */
    protected function resolveWrapExecutionType(
        BaseData|BaseDataCollectable $value,
        TransformationContext $context,
    ): WrapExecutionType {
        if ($context->wrapExecutionType === WrapExecutionType::Disabled) {
            return WrapExecutionType::Disabled;
        }

        if ($value instanceof BaseData) {
            return WrapExecutionType::TemporarilyDisabled;
        }

        return $context->wrapExecutionType === WrapExecutionType::Enabled
            ? WrapExecutionType::Enabled
            : WrapExecutionType::TemporarilyDisabled;
    }

    /**
     * Merge a reached data instance's partials into its local context.
     */
    protected function mergeInstancePartials(
        BaseData|BaseDataCollectable $value,
        TransformationContext $context,
    ): TransformationContext {
        if (! $value instanceof IncludeableData) {
            return $context;
        }

        $partialDefinitions = $value->getPartialsDefinition();

        if ($partialDefinitions->isEmpty()) {
            return $context;
        }

        return $context->withMergedPartials($partialDefinitions->resolve(
            $value,
            consumeTemporary: true,
        ));
    }

    /**
     * Get a custom transformer for a property value.
     *
     * @param array<string, object> $extensions
     */
    protected function propertyTransformer(
        DataProperty $property,
        mixed $value,
        TransformationContext $context,
        array &$extensions,
    ): ?Transformer {
        if ($property->transformer !== null) {
            $key = 'attribute-transformer:' . spl_object_id($property->transformer);

            if (! isset($extensions[$key])) {
                /** @var WithCastAndTransformer|WithTransformer $attribute */
                $attribute = $property->transformer->newInstance();
                $extensions[$key] = $attribute->get();
            }

            /** @var Transformer */
            return $extensions[$key];
        }

        if (($transformer = $this->runtimeTransformer($value, $context, $extensions)) !== null) {
            return $transformer;
        }

        foreach ($property->configuredTransformers as $transformer) {
            return $this->resolveTransformer($transformer, $extensions);
        }

        return null;
    }

    /**
     * Get an operation transformer matching a runtime value.
     *
     * @param array<string, object> $extensions
     */
    protected function runtimeTransformer(
        mixed $value,
        TransformationContext $context,
        array &$extensions,
    ): ?Transformer {
        foreach ($context->transformers as $transformable => $transformer) {
            if (! $this->matchesTransformable($transformable, $value)) {
                continue;
            }

            return $this->resolveTransformer($transformer, $extensions);
        }

        return null;
    }

    /**
     * Determine if a transformer key matches a runtime value.
     */
    protected function matchesTransformable(string $transformable, mixed $value): bool
    {
        if (! is_object($value)) {
            return get_debug_type($value) === $transformable;
        }

        return $value::class === $transformable || is_a($value, $transformable);
    }

    /**
     * Resolve one transformer once for the current root operation.
     *
     * @param Transformer|class-string<Transformer> $transformer
     * @param array<string, object> $extensions
     */
    protected function resolveTransformer(
        Transformer|string $transformer,
        array &$extensions,
    ): Transformer {
        if ($transformer instanceof Transformer) {
            return $transformer;
        }

        $key = 'transformer:' . $transformer;

        /** @var Transformer */
        return $extensions[$key] ??= $this->container->make($transformer);
    }

    /**
     * Get iterable item metadata accepted by the runtime value.
     */
    protected function iterableTypeForValue(DataProperty $property, mixed $value): ?Type
    {
        foreach ($property->type->getIterableTypes() as $type) {
            if ($type->acceptsValue($value)) {
                return $type->iterableItemType;
            }
        }

        return null;
    }

    /**
     * Transform typed iterable items while preserving supported containers.
     *
     * @param array<string, object> $extensions
     */
    protected function transformIterable(
        DataProperty $property,
        iterable $items,
        Type $itemType,
        TransformationContext $context,
        array &$extensions,
    ): array {
        $transformed = [];

        foreach ($items as $key => $item) {
            $transformed[$key] = $this->transformIterableItem(
                $property,
                $item,
                $itemType,
                $context,
                $extensions,
            );
        }

        return $transformed;
    }

    /**
     * Transform one typed iterable item.
     *
     * @param array<string, object> $extensions
     */
    protected function transformIterableItem(
        DataProperty $property,
        mixed $value,
        Type $type,
        TransformationContext $context,
        array &$extensions,
    ): mixed {
        if ($value === null) {
            return null;
        }

        if (($transformer = $this->runtimeTransformer($value, $context, $extensions)) !== null) {
            return $transformer->transform($property, $value, $context);
        }

        if ($value instanceof BaseData || $value instanceof BaseDataCollectable) {
            $context = $context->withWrapExecutionType(
                $this->resolveWrapExecutionType($value, $context),
            );

            if ($value instanceof BaseData) {
                return $this->transformData(
                    $value,
                    $this->mergeInstancePartials($value, $context),
                    $extensions,
                );
            }

            return $this->transformCollectable(
                $value,
                $this->mergeInstancePartials($value, $context),
                $extensions,
            );
        }

        foreach ($type->getNamedTypes() as $namedType) {
            if ($namedType->iterableItemType !== null && $namedType->acceptsValue($value)) {
                return $this->transformIterable(
                    $property,
                    $value,
                    $namedType->iterableItemType,
                    $context,
                    $extensions,
                );
            }
        }

        return $this->transformBuiltIn($value);
    }

    /**
     * Transform one fixed built-in value.
     */
    protected function transformBuiltIn(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            if ($this->dateTimezone !== null) {
                $value = DateTimeImmutable::createFromInterface($value)
                    ->setTimezone($this->dateTimezone);
            }

            return $value->format(ltrim($this->config->dateFormats[0], '!'));
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof Arrayable) {
            return $value->toArray();
        }

        return $value;
    }

    /**
     * Apply resolved partials to an unchanged nested data value.
     */
    protected function propagatePartials(
        BaseData|BaseDataCollectable $value,
        TransformationContext $context,
        string $property,
    ): void {
        if (! $context->hasPartials() || ! $value instanceof IncludeableData) {
            return;
        }

        $value->getPartialsDefinition()->addResolved(
            $context->partialsForNestedProperty($property),
        );
    }

    /**
     * Apply resolved partials to unchanged data items in a typed iterable.
     */
    protected function propagateIterablePartials(
        iterable $items,
        TransformationContext $context,
        string $property,
    ): void {
        if (! $context->hasPartials()) {
            return;
        }

        $definitions = $context->partialsForNestedProperty($property);

        foreach ($items as $item) {
            if ($item instanceof IncludeableData) {
                $item->getPartialsDefinition()->addResolved($definitions);
            }
        }
    }

    /**
     * Apply only and except selections to a plain array value.
     *
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    protected function filterArray(
        array $value,
        ?PartialTree $only,
        ?PartialTree $except,
    ): array
    {
        if ($except?->all) {
            $value = [];
        } elseif ($except !== null) {
            foreach ($except->children as $key => $partial) {
                if ($partial->selected || $partial->all) {
                    unset($value[$key]);
                } elseif (array_key_exists($key, $value) && is_array($value[$key])) {
                    $value[$key] = $this->filterArray(
                        $value[$key],
                        null,
                        $partial,
                    );
                }
            }
        }

        if ($only === null || $only->children === []) {
            return $value;
        }

        foreach ($value as $key => $item) {
            if (! isset($only->children[$key])) {
                unset($value[$key]);

                continue;
            }

            $partial = $only->children[$key];

            if ($partial->children !== [] && is_array($item)) {
                $value[$key] = $this->filterArray(
                    $item,
                    $partial,
                    null,
                );
            }
        }

        return $value;
    }
}
