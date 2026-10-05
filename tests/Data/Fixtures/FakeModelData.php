<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures;

use Hypervel\Data\Attributes\DataCollectionOf;
use Hypervel\Data\Data;
use Hypervel\Data\DataCollection;
use Hypervel\Data\Optional;
use Hypervel\Support\CarbonImmutable;

class FakeModelData extends Data
{
    /**
     * Create a fixture for a fake model.
     */
    public function __construct(
        public string $string,
        public ?string $nullable,
        public CarbonImmutable $date,
        #[DataCollectionOf(FakeNestedModelData::class)]
        public Optional|DataCollection|null $fake_nested_models,
        public string $accessor,
        public string $old_accessor,
        public string $translated,
    ) {
    }
}
