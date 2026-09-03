<?php

declare(strict_types=1);

namespace Hypervel\Data\Concerns;

use Hypervel\Container\Container;
use Hypervel\Data\Support\Transformation\DataTransformer;
use Hypervel\Data\Support\Transformation\TransformationContext;
use Hypervel\Data\Support\Transformation\TransformationContextFactory;
use Hypervel\Support\Json;

trait TransformableData
{
    /**
     * Transform the data object to an array.
     *
     * @return array<array-key, mixed>
     */
    public function transform(
        TransformationContextFactory|TransformationContext|null $transformationContext = null,
    ): array {
        $transformer = Container::getInstance()->make(DataTransformer::class);
        $transformationContext = match (true) {
            $transformationContext instanceof TransformationContext => $transformationContext,
            $transformationContext instanceof TransformationContextFactory => $transformationContext->get($this),
            default => $transformer->defaultContext($this),
        };

        return $transformer->transform($this, $transformationContext);
    }

    /**
     * Get all visible properties without transforming their values.
     *
     * @return array<array-key, mixed>
     */
    public function all(): array
    {
        $context = Container::getInstance()
            ->make(DataTransformer::class)
            ->allContext($this);

        return $this->transform($context);
    }

    /**
     * Get the data object as an array.
     *
     * @return array<array-key, mixed>
     */
    public function toArray(): array
    {
        return $this->transform();
    }

    /**
     * Convert the data object to its JSON representation.
     */
    public function toJson(int $options = 0): string
    {
        return Json::encode($this->transform(), $options);
    }

    /**
     * Get the data that should be serialized to JSON.
     *
     * @return array<array-key, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->transform();
    }
}
