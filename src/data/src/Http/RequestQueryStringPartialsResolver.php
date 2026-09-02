<?php

declare(strict_types=1);

namespace Hypervel\Data\Http;

use Hypervel\Data\Contracts\BaseData;
use Hypervel\Data\Contracts\BaseDataCollectable;
use Hypervel\Data\Contracts\ResponsableData;
use Hypervel\Data\Exceptions\CannotPerformPartialOnDataField;
use Hypervel\Data\Support\DataClass;
use Hypervel\Data\Support\DataClassRepository;
use Hypervel\Data\Support\DataProperty;
use Hypervel\Data\Support\Transformation\PartialTree;
use Hypervel\Data\Support\Transformation\TransformationContextFactory;
use Hypervel\Http\Request;

class RequestQueryStringPartialsResolver
{
    /** @var list<'except'|'exclude'|'include'|'only'> */
    private const array PARTIAL_TYPES = ['include', 'exclude', 'only', 'except'];

    /**
     * Create a request query string partials resolver.
     */
    public function __construct(
        protected readonly DataClassRepository $dataClasses,
    ) {
    }

    /**
     * Apply allowed request partials to a transformation context factory.
     *
     * @param (BaseData&ResponsableData)|(BaseDataCollectable&ResponsableData) $data
     */
    public function resolve(
        (BaseData&ResponsableData)|(BaseDataCollectable&ResponsableData) $data,
        Request $request,
        TransformationContextFactory $contextFactory,
    ): TransformationContextFactory {
        $dataClass = $this->dataClasses->get(match (true) {
            $data instanceof BaseData => $data::class,
            default => $data->getDataClass(),
        });
        $allowedPartials = [];

        foreach (self::PARTIAL_TYPES as $type) {
            if (! $request->has($type)) {
                continue;
            }

            $paths = $this->resolvePaths(
                $request->input($type),
                $type,
                $dataClass,
                $allowedPartials,
            );

            if ($paths === []) {
                continue;
            }

            match ($type) {
                'include' => $contextFactory->include(...$paths),
                'exclude' => $contextFactory->exclude(...$paths),
                'only' => $contextFactory->only(...$paths),
                'except' => $contextFactory->except(...$paths),
            };
        }

        return $contextFactory;
    }

    /**
     * Resolve valid property-name paths from one request value.
     *
     * @param 'except'|'exclude'|'include'|'only' $type
     * @param array<string, ?array> $allowedPartials
     * @param-out array<string, ?array> $allowedPartials
     * @return list<string>
     */
    protected function resolvePaths(
        mixed $value,
        string $type,
        DataClass $dataClass,
        array &$allowedPartials,
    ): array {
        if (! is_string($value) && ! is_array($value)) {
            return [];
        }

        $values = is_string($value) ? explode(',', $value) : $value;
        $paths = [];

        foreach ($values as $path) {
            if (! is_string($path)) {
                continue;
            }

            try {
                $partial = PartialTree::compile([$path]);
            } catch (CannotPerformPartialOnDataField) {
                continue;
            }

            if ($partial === null) {
                continue;
            }

            foreach ($this->validateTree($partial, $type, $dataClass, $allowedPartials) as $validPath) {
                $paths[$validPath] = true;
            }
        }

        return array_keys($paths);
    }

    /**
     * Validate a compiled partial tree against one data class.
     *
     * @param 'except'|'exclude'|'include'|'only' $type
     * @param array<string, ?array> $allowedPartials
     * @param-out array<string, ?array> $allowedPartials
     * @return list<string>
     */
    protected function validateTree(
        PartialTree $partial,
        string $type,
        DataClass $dataClass,
        array &$allowedPartials,
    ): array {
        $allowed = $this->allowedPartials($type, $dataClass, $allowedPartials);

        if ($partial->all) {
            return $this->allowsAll($allowed) ? ['*'] : [];
        }

        $paths = [];

        foreach ($partial->children as $field => $nestedPartial) {
            $property = $this->findProperty($field, $dataClass);

            if ($property === null || ! $this->allows($allowed, $property->name)) {
                continue;
            }

            if ($nestedPartial->selected) {
                $paths[$property->name] = true;
            }

            if (! $nestedPartial->all && $nestedPartial->children === []) {
                continue;
            }

            $nestedDataClass = $this->nestedDataClass($property);

            if ($nestedDataClass === null) {
                continue;
            }

            $nestedPaths = $this->validateTree(
                $nestedPartial,
                $type,
                $nestedDataClass,
                $allowedPartials,
            );

            if ($nestedPaths === []) {
                $paths[$property->name] = true;

                continue;
            }

            foreach ($nestedPaths as $nestedPath) {
                $path = $property->name . '.' . $nestedPath;
                $paths[$path] = true;
            }
        }

        return array_keys($paths);
    }

    /**
     * Find a data property by its PHP or mapped output name.
     */
    protected function findProperty(
        string $field,
        DataClass $dataClass,
    ): ?DataProperty {
        if (isset($dataClass->properties[$field])) {
            return $dataClass->properties[$field];
        }

        $property = $dataClass->outputMappedProperties[$field] ?? null;

        return $property === null ? null : $dataClass->properties[$property];
    }

    /**
     * Get the one nested data class represented by a property.
     */
    protected function nestedDataClass(DataProperty $property): ?DataClass
    {
        $type = $property->type->getDataObjectType()
            ?? $property->type->getDataCollectableType();

        return $type === null ? null : $this->dataClasses->get($type->dataClass);
    }

    /**
     * Resolve one class-owned allowlist once for this response.
     *
     * @param 'except'|'exclude'|'include'|'only' $type
     * @param array<string, ?array> $allowedPartials
     * @param-out array<string, ?array> $allowedPartials
     */
    protected function allowedPartials(
        string $type,
        DataClass $dataClass,
        array &$allowedPartials,
    ): ?array {
        $key = $type . ':' . $dataClass->name;

        if (array_key_exists($key, $allowedPartials)) {
            return $allowedPartials[$key];
        }

        if (! $dataClass->responsable) {
            return $allowedPartials[$key] = [];
        }

        /** @var class-string<ResponsableData> $class */
        $class = $dataClass->name;

        return $allowedPartials[$key] = match ($type) {
            'include' => $class::allowedRequestIncludes(),
            'exclude' => $class::allowedRequestExcludes(),
            'only' => $class::allowedRequestOnly(),
            'except' => $class::allowedRequestExcept(),
        };
    }

    /**
     * Determine whether an allowlist permits every property.
     */
    protected function allowsAll(?array $allowed): bool
    {
        return $allowed === null || $allowed === ['*'];
    }

    /**
     * Determine whether an allowlist permits one property.
     */
    protected function allows(?array $allowed, string $property): bool
    {
        return $this->allowsAll($allowed) || in_array($property, $allowed, true);
    }
}
