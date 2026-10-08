<?php

declare(strict_types=1);

namespace Hypervel\Database\PHPStan;

use Hypervel\Database\Eloquent\Model;

/**
 * Declare the builder macros that SoftDeletingScope adds, for static analysis.
 *
 * Keep these signatures in step with the scope's macros.
 *
 * @template TModel of Model
 */
interface SoftDeletingMacros
{
    /**
     * Include soft-deleted models in the results, or exclude them when false.
     */
    public function withTrashed(bool $withTrashed = true): static;

    /**
     * Exclude soft-deleted models from the results.
     */
    public function withoutTrashed(): static;

    /**
     * Only include soft-deleted models in the results.
     */
    public function onlyTrashed(): static;

    /**
     * Restore the matching soft-deleted models.
     */
    public function restore(): int;

    /**
     * Get the first matching model or create it, then restore it.
     *
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $values
     * @return TModel
     */
    public function restoreOrCreate(array $attributes = [], array $values = []): Model;

    /**
     * Create the model or get the matching one, then restore it.
     *
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $values
     * @return TModel
     */
    public function createOrRestore(array $attributes = [], array $values = []): Model;
}
