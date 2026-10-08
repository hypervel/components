<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures;

use Hypervel\Data\Data;
use Hypervel\Data\Lazy;
use Hypervel\Data\Optional;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\Data\Fixtures\Models\FakeNestedModel;

class FakeNestedModelData extends Data
{
    /**
     * Create a fixture for a fake nested model.
     */
    public function __construct(
        public string $string,
        public ?string $nullable,
        public CarbonImmutable $date,
        public Optional|Lazy|FakeModelData|null $fake_model
    ) {
    }

    /**
     * Create the fixture with its parent model included once loaded.
     */
    public static function createWithLazyWhenLoaded(FakeNestedModel $model): self
    {
        return new self(
            $model->string,
            $model->nullable,
            $model->date,
            Lazy::whenLoaded('fakeModel', $model, fn (): FakeModelData => FakeModelData::from($model->fakeModel)),
        );
    }
}
