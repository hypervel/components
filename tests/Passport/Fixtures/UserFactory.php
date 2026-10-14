<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Fixtures;

use Hypervel\Database\Eloquent\Factories\Factory;
use Hypervel\Testbench\Factories\UserFactory as TestbenchUserFactory;

/**
 * @template TModel of User
 *
 * @extends Factory<TModel>
 */
class UserFactory extends TestbenchUserFactory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<TModel>
     */
    protected ?string $model = User::class;
}
