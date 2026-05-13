<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Traits;

use Hypervel\Foundation\Testing\Attributes\Seed;
use Hypervel\Foundation\Testing\Attributes\Seeder;
use ReflectionClass;

trait CanConfigureMigrationCommands
{
    /**
     * The parameters that should be used when running "migrate:fresh".
     */
    protected function migrateFreshUsing(): array
    {
        $seeder = $this->seeder();

        return array_merge(
            [
                '--drop-views' => $this->shouldDropViews(),
                '--drop-types' => $this->shouldDropTypes(),
            ],
            $seeder ? ['--seeder' => $seeder] : ['--seed' => $this->shouldSeed()]
        );
    }

    /**
     * Determine if views should be dropped when refreshing the database.
     */
    protected function shouldDropViews(): bool
    {
        return property_exists($this, 'dropViews') ? $this->dropViews : false;
    }

    /**
     * Determine if types should be dropped when refreshing the database.
     */
    protected function shouldDropTypes(): bool
    {
        return property_exists($this, 'dropTypes') ? $this->dropTypes : false;
    }

    /**
     * Determine if the seed task should be run when refreshing the database.
     */
    protected function shouldSeed(): bool
    {
        $class = new ReflectionClass($this);

        do {
            if ($class->getAttributes(Seed::class) !== []) {
                return true;
            }
        } while ($class = $class->getParentClass());

        return property_exists($this, 'seed') ? $this->seed : false;
    }

    /**
     * Determine the specific seeder class that should be used when refreshing the database.
     */
    protected function seeder(): mixed
    {
        $class = new ReflectionClass($this);

        do {
            $seeder = $class->getAttributes(Seeder::class);

            if ($seeder !== []) {
                return $seeder[0]->newInstance()->class;
            }
        } while ($class = $class->getParentClass());

        return property_exists($this, 'seeder') ? $this->seeder : false;
    }
}
