<?php

declare(strict_types=1);

use Faker\Generator as Faker;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Storage\EntryModel;

/* @phpstan-ignore-next-line */
$factory->define(EntryModel::class, function (Faker $faker) {
    return [
        'sequence' => random_int(1, 10000),
        'uuid' => $faker->uuid(),
        'batch_id' => $faker->uuid(),
        'type' => $faker->randomElement([
            EntryType::CACHE,
            EntryType::CLIENT_REQUEST,
            EntryType::COMMAND,
            EntryType::DUMP,
            EntryType::EVENT,
            EntryType::EXCEPTION,
            EntryType::JOB,
            EntryType::LOG,
            EntryType::MAIL,
            EntryType::MODEL,
            EntryType::NOTIFICATION,
            EntryType::QUERY,
            EntryType::REDIS,
            EntryType::REQUEST,
            EntryType::SCHEDULED_TASK,
        ]),
        'content' => [$faker->word() => $faker->word()],
    ];
});
