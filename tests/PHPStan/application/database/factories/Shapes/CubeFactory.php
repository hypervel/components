<?php

declare(strict_types=1);

namespace Database\Factories\Shapes;

use App\Shapes\Cube;
use Hypervel\Database\Eloquent\Factories\Factory;

/** @extends Factory<Cube> */
class CubeFactory extends Factory
{
    protected ?string $model = Cube::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [];
    }
}
