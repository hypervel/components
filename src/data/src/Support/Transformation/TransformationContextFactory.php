<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Transformation;

use Hypervel\Container\Container;
use Hypervel\Contracts\Container\Transient;
use Hypervel\Data\Contracts\IncludeableData;
use Hypervel\Data\Support\DataConfig;
use Hypervel\Data\Support\Partials\ForwardsToPartialsDefinition;
use Hypervel\Data\Support\Partials\PartialDefinition;
use Hypervel\Data\Support\Partials\PartialsDefinition;
use Hypervel\Data\Support\Wrapping\WrapExecutionType;
use Hypervel\Data\Transformers\Transformer;

class TransformationContextFactory implements Transient
{
    use ForwardsToPartialsDefinition;

    protected bool $transformValues = true;

    protected bool $mapPropertyNames = true;

    protected bool $constructable = false;

    protected WrapExecutionType $wrapExecutionType = WrapExecutionType::Disabled;

    /** @var array<string, class-string<Transformer>|Transformer> */
    protected array $transformers = [];

    protected ?int $maxDepth;

    protected readonly ?int $configuredMaxDepth;

    protected PartialsDefinition $partialDefinitions;

    /**
     * Create a transformation context factory.
     */
    public function __construct(DataConfig $config)
    {
        $this->configuredMaxDepth = $config->maxTransformationDepth;
        $this->maxDepth = $this->configuredMaxDepth;
        $this->partialDefinitions = new PartialsDefinition;
    }

    /**
     * Create a fresh transformation context factory.
     */
    public static function create(): static
    {
        // Mutable factories stay fresh; subclasses customize this path through late static binding, not container bindings.
        return new static(Container::getInstance()->make(DataConfig::class));
    }

    /**
     * Create a fresh constructable persistence context factory.
     */
    public static function forPersistence(): static
    {
        $factory = static::create();
        $factory->constructable = true;

        return $factory;
    }

    /**
     * Create the immutable constructable persistence context.
     */
    public static function persistenceContext(?int $maxDepth): TransformationContext
    {
        return new TransformationContext(
            transformValues: true,
            mapPropertyNames: false,
            constructable: true,
            include: PartialTree::compile(['*']),
            wrapExecutionType: WrapExecutionType::Disabled,
            maxDepth: $maxDepth,
        );
    }

    /**
     * Build the context for one root transformation.
     */
    public function get(object $data): TransformationContext
    {
        if ($this->constructable) {
            return static::persistenceContext($this->configuredMaxDepth);
        }

        $dataPartials = $data instanceof IncludeableData && $data->hasPartialsDefinition()
            ? $data->getPartialsDefinition()
            : null;

        if ($this->partialDefinitions->isEmpty() && ($dataPartials?->isEmpty() ?? true)) {
            return new TransformationContext(
                transformValues: $this->transformValues,
                mapPropertyNames: $this->mapPropertyNames,
                transformers: $this->transformers,
                wrapExecutionType: $this->wrapExecutionType,
                maxDepth: $this->maxDepth,
            );
        }

        $partials = $this->partialDefinitions->resolve($data);

        if ($dataPartials !== null) {
            $resolvedDataPartials = $dataPartials->resolve(
                $data,
                consumeTemporary: true,
            );

            foreach ($partials as $type => $paths) {
                array_push($partials[$type], ...$resolvedDataPartials[$type]);
            }
        }

        return new TransformationContext(
            transformValues: $this->transformValues,
            mapPropertyNames: $this->mapPropertyNames,
            include: PartialTree::compile(self::paths($partials['include'])),
            exclude: PartialTree::compile(self::paths($partials['exclude'])),
            only: PartialTree::compile(self::paths($partials['only'])),
            except: PartialTree::compile(self::paths($partials['except'])),
            partialDefinitions: $partials,
            transformers: $this->transformers,
            wrapExecutionType: $this->wrapExecutionType,
            maxDepth: $this->maxDepth,
        );
    }

    /**
     * Enable or disable value transformation.
     */
    public function withValueTransformation(bool $transformValues = true): static
    {
        $this->transformValues = $transformValues;

        return $this;
    }

    /**
     * Disable or enable value transformation.
     */
    public function withoutValueTransformation(bool $withoutValueTransformation = true): static
    {
        $this->transformValues = ! $withoutValueTransformation;

        return $this;
    }

    /**
     * Enable or disable output property-name mapping.
     */
    public function withPropertyNameMapping(bool $mapPropertyNames = true): static
    {
        $this->mapPropertyNames = $mapPropertyNames;

        return $this;
    }

    /**
     * Disable or enable output property-name mapping.
     */
    public function withoutPropertyNameMapping(bool $withoutPropertyNameMapping = true): static
    {
        $this->mapPropertyNames = ! $withoutPropertyNameMapping;

        return $this;
    }

    /**
     * Set wrapping behavior for the transformation.
     */
    public function withWrapExecutionType(WrapExecutionType $wrapExecutionType): static
    {
        $this->wrapExecutionType = $wrapExecutionType;

        return $this;
    }

    /**
     * Disable wrapping for the transformation.
     */
    public function withoutWrapping(): static
    {
        $this->wrapExecutionType = WrapExecutionType::Disabled;

        return $this;
    }

    /**
     * Enable wrapping for the transformation.
     */
    public function withWrapping(): static
    {
        $this->wrapExecutionType = WrapExecutionType::Enabled;

        return $this;
    }

    /**
     * Add a transformer for one declared or runtime type.
     *
     * @param class-string<Transformer>|Transformer $transformer
     */
    public function withTransformer(string $transformable, Transformer|string $transformer): static
    {
        $this->transformers[$transformable] = $transformer;

        return $this;
    }

    /**
     * Set the maximum nested transformation depth.
     */
    public function maxDepth(?int $maxDepth): static
    {
        $this->maxDepth = $maxDepth;

        return $this;
    }

    /**
     * Get paths from resolved partial definitions.
     *
     * @param list<PartialDefinition> $definitions
     * @return list<string>
     */
    private static function paths(array $definitions): array
    {
        $paths = [];

        foreach ($definitions as $definition) {
            $paths[] = $definition->path;
        }

        return $paths;
    }

    /**
     * Get the partial definition store.
     */
    protected function getPartialsDefinition(): PartialsDefinition
    {
        return $this->partialDefinitions;
    }
}
