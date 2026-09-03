<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Transformation;

use Hypervel\Data\Support\Partials\PartialDefinition;
use Hypervel\Data\Support\Wrapping\WrapExecutionType;
use Hypervel\Data\Transformers\Transformer;

final readonly class TransformationContext
{
    /**
     * Create an immutable transformation context.
     *
     * @param array{include: list<PartialDefinition>, exclude: list<PartialDefinition>, only: list<PartialDefinition>, except: list<PartialDefinition>}|array{} $partialDefinitions
     * @param array<string, class-string<Transformer>|Transformer> $transformers
     */
    public function __construct(
        public bool $transformValues = true,
        public bool $mapPropertyNames = true,
        public bool $constructable = false,
        public ?PartialTree $include = null,
        public ?PartialTree $exclude = null,
        public ?PartialTree $only = null,
        public ?PartialTree $except = null,
        public array $partialDefinitions = [
            'include' => [],
            'exclude' => [],
            'only' => [],
            'except' => [],
        ],
        public array $transformers = [],
        public WrapExecutionType $wrapExecutionType = WrapExecutionType::Disabled,
        public int $depth = 0,
        public ?int $maxDepth = null,
    ) {
    }

    /**
     * Determine if this operation has partial selections.
     */
    public function hasPartials(): bool
    {
        return $this->include !== null
            || $this->exclude !== null
            || $this->only !== null
            || $this->except !== null;
    }

    /**
     * Merge resolved instance partials into this context.
     *
     * @param array{include: list<PartialDefinition>, exclude: list<PartialDefinition>, only: list<PartialDefinition>, except: list<PartialDefinition>} $partialDefinitions
     */
    public function withMergedPartials(array $partialDefinitions): self
    {
        if ($partialDefinitions['include'] === []
            && $partialDefinitions['exclude'] === []
            && $partialDefinitions['only'] === []
            && $partialDefinitions['except'] === []
        ) {
            return $this;
        }

        return new self(
            transformValues: $this->transformValues,
            mapPropertyNames: $this->mapPropertyNames,
            constructable: $this->constructable,
            include: self::mergeTree($this->include, $partialDefinitions['include']),
            exclude: self::mergeTree($this->exclude, $partialDefinitions['exclude']),
            only: self::mergeTree($this->only, $partialDefinitions['only']),
            except: self::mergeTree($this->except, $partialDefinitions['except']),
            partialDefinitions: $this->partialDefinitions,
            transformers: $this->transformers,
            wrapExecutionType: $this->wrapExecutionType,
            depth: $this->depth,
            maxDepth: $this->maxDepth,
        );
    }

    /**
     * Create the same context with different wrapping behavior.
     */
    public function withWrapExecutionType(WrapExecutionType $wrapExecutionType): self
    {
        return new self(
            transformValues: $this->transformValues,
            mapPropertyNames: $this->mapPropertyNames,
            constructable: $this->constructable,
            include: $this->include,
            exclude: $this->exclude,
            only: $this->only,
            except: $this->except,
            partialDefinitions: $this->partialDefinitions,
            transformers: $this->transformers,
            wrapExecutionType: $wrapExecutionType,
            depth: $this->depth,
            maxDepth: $this->maxDepth,
        );
    }

    /**
     * Resolve partial definitions for a raw nested property.
     *
     * @return array{include: list<PartialDefinition>, exclude: list<PartialDefinition>, only: list<PartialDefinition>, except: list<PartialDefinition>}
     */
    public function partialsForNestedProperty(string $property): array
    {
        $nested = [
            'include' => [],
            'exclude' => [],
            'only' => [],
            'except' => [],
        ];

        foreach ($this->partialDefinitions as $type => $definitions) {
            foreach ($definitions as $definition) {
                if (($definition = $definition->nested($property)) !== null) {
                    $nested[$type][] = $definition;
                }
            }
        }

        return $nested;
    }

    /**
     * Create the context for one nested property.
     */
    public function child(
        string $property,
        ?WrapExecutionType $wrapExecutionType = null,
    ): self {
        return new self(
            transformValues: $this->transformValues,
            mapPropertyNames: $this->mapPropertyNames,
            constructable: $this->constructable,
            include: $this->include?->child($property),
            exclude: $this->exclude?->child($property),
            only: $this->only?->child($property),
            except: $this->except?->child($property),
            partialDefinitions: [],
            transformers: $this->transformers,
            wrapExecutionType: $wrapExecutionType ?? $this->wrapExecutionType,
            depth: $this->depth + 1,
            maxDepth: $this->maxDepth,
        );
    }

    /**
     * Merge one resolved definition group into a compiled tree.
     *
     * @param list<PartialDefinition> $partialDefinitions
     */
    private static function mergeTree(?PartialTree $tree, array $partialDefinitions): ?PartialTree
    {
        $other = PartialTree::compile(array_map(
            static fn (PartialDefinition $definition): string => $definition->path,
            $partialDefinitions,
        ));

        return $tree?->merge($other) ?? $other;
    }
}
