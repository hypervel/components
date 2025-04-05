<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Traits;

use Hyperf\Contract\ConfigInterface;

trait CanConfigureMigrationCommands
{
    /**
     * The parameters that should be used when running "migrate:fresh".
     */
    protected function migrateFreshUsing(): array
    {
        $seeder = $this->seeder();
        $connection = $this->app
            ->get(ConfigInterface::class)
            ->get('database.default');

        return array_merge(
            [
                '--drop-views' => $this->shouldDropViews(),
                '--database' => $connection,
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
     * Determine if the seed task should be run when refreshing the database.
     */
    protected function shouldSeed(): bool
    {
        return property_exists($this, 'seed') ? $this->seed : false;
    }

    /**
     * Determine the specific seeder class that should be used when refreshing the database.
     */
    protected function seeder(): mixed
    {
        return property_exists($this, 'seeder') ? $this->seeder : false;
    }
}
