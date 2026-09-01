<?php

declare(strict_types=1);

namespace Hypervel\Data\Contracts;

use Hypervel\Contracts\Database\Eloquent\Castable as EloquentCastable;
use Hypervel\Contracts\Database\Eloquent\CastsAttributes;
use Hypervel\Contracts\Database\Eloquent\CastsInboundAttributes;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Contracts\Support\Jsonable;
use Hypervel\Data\Support\Transformation\TransformationContext;
use Hypervel\Data\Support\Transformation\TransformationContextFactory;
use JsonSerializable;

/**
 * @extends Arrayable<array-key, mixed>
 */
interface TransformableData extends JsonSerializable, Jsonable, Arrayable, EloquentCastable
{
    /**
     * Transform the data object to an array.
     */
    public function transform(
        null|TransformationContextFactory|TransformationContext $transformationContext = null,
    ): array;

    /**
     * Get all visible data properties.
     */
    public function all(): array;

    /**
     * Get the data object as an array.
     */
    public function toArray(): array;

    /**
     * Convert the data object to its JSON representation.
     */
    public function toJson(int $options = 0): string;

    /**
     * Get the data that should be serialized to JSON.
     */
    public function jsonSerialize(): array;

    /**
     * Get the Eloquent caster for the data object.
     */
    public static function castUsing(array $arguments): CastsAttributes|CastsInboundAttributes|string;
}
