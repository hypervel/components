<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing;

use Faker\Factory;
use Faker\Generator;

trait WithFaker
{
    /**
     * The Faker instance.
     */
    protected ?Generator $faker = null;

    /**
     * Set up the Faker instance.
     */
    protected function setUpFaker(): void
    {
        $this->faker = $this->makeFaker();
    }

    /**
     * Get the default Faker instance for a given locale.
     */
    protected function faker(?string $locale = null): Generator
    {
        return is_null($locale) ? $this->faker : $this->makeFaker($locale);
    }

    /**
     * Create a Faker instance for the given locale.
     */
    protected function makeFaker(?string $locale = null): Generator
    {
        if (isset($this->app)) {
            $locale ??= $this->app->make('config')->get('app.faker_locale', Factory::DEFAULT_LOCALE);

            if ($this->app->bound(Generator::class)) {
                return $this->app->make(Generator::class, ['locale' => $locale]);
            }
        }

        return Factory::create($locale ?? Factory::DEFAULT_LOCALE);
    }
}
