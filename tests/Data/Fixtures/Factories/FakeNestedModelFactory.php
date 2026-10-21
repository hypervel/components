<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures\Factories;

use Hypervel\Database\Eloquent\Factories\Factory;
use Hypervel\Tests\Data\Fixtures\Models\FakeModel;
use Hypervel\Tests\Data\Fixtures\Models\FakeNestedModel;

class FakeNestedModelFactory extends Factory
{
    protected ?string $model = FakeNestedModel::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'string' => $this->faker->name,
            'nullable' => null,
            'date' => $this->faker->dateTime(),
            'fake_model_id' => FakeModel::factory(),
        ];
    }
}
