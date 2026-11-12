<?php

declare(strict_types=1);

namespace Database\Factories\Shapes;

use App\Shapes\Hexagon;
use Hypervel\Database\Eloquent\Factories\Factory;

/** @extends Factory<Hexagon> */
class HexagonFactory extends Factory
{
    protected ?string $model = Hexagon::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [];
    }
}
