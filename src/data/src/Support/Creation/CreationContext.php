<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Creation;

use Closure;
use Hypervel\Data\Casts\Cast;
use Hypervel\Data\Contracts\BaseData;
use Hypervel\Data\Normalizers\Normalizer;

/**
 * Immutable options and hooks shared by one complete construction operation.
 *
 * @template TData of BaseData
 */
final readonly class CreationContext
{
    /**
     * Create a new creation context.
     *
     * @param class-string<TData> $dataClass
     * @param list<string> $ignoredMagicalMethods
     * @param array<string, Cast|class-string<Cast>> $casts
     * @param list<Normalizer|class-string<Normalizer>> $normalizers
     * @param list<Closure> $prepareDataHooks
     * @param list<Closure> $beforeValidationHooks
     * @param list<Closure> $beforeRulesHooks
     * @param list<Closure> $afterRulesHooks
     * @param list<Closure> $withValidatorHooks
     * @param list<Closure> $afterValidationHooks
     * @param list<Closure> $beforeCreationHooks
     * @param list<Closure> $afterCreationHooks
     * @param non-empty-list<string> $dateFormats
     */
    public function __construct(
        public string $dataClass,
        public CreationMode $mode = CreationMode::Create,
        public ValidationStrategy $validationStrategy = ValidationStrategy::OnlyRequests,
        public bool $mapPropertyNames = true,
        public bool $disableMagicalCreation = false,
        public array $ignoredMagicalMethods = [],
        public array $casts = [],
        public array $normalizers = [],
        public array $prepareDataHooks = [],
        public array $beforeValidationHooks = [],
        public array $beforeRulesHooks = [],
        public array $afterRulesHooks = [],
        public array $withValidatorHooks = [],
        public array $afterValidationHooks = [],
        public array $beforeCreationHooks = [],
        public array $afterCreationHooks = [],
        public array $dateFormats = [DATE_ATOM],
        public ?string $dateTimezone = null,
    ) {
    }
}
