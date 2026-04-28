<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Database\Factories;

use Hypervel\Database\Eloquent\Factories\Factory;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Storage\EntryModel;

class EntryModelFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected ?string $model = EntryModel::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'sequence' => random_int(1, 10000),
            'uuid' => $this->faker->uuid(),
            'batch_id' => $this->faker->uuid(),
            'type' => $this->faker->randomElement([
                EntryType::CACHE, EntryType::CLIENT_REQUEST, EntryType::COMMAND, EntryType::DUMP, EntryType::EVENT,
                EntryType::EXCEPTION, EntryType::JOB, EntryType::LOG, EntryType::MAIL, EntryType::MODEL,
                EntryType::NOTIFICATION, EntryType::QUERY, EntryType::REDIS, EntryType::REVERB,
                EntryType::REQUEST, EntryType::SCHEDULED_TASK,
            ]),
            'content' => [$this->faker->word() => $this->faker->word()],
        ];
    }
}
