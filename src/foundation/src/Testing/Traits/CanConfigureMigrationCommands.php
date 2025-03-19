<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Traits;

use Hyperf\Contract\ConfigInterface;

trait CanConfigureMigrationCommands
{
    /**
     * The parameters that should be used when running "migrate:fresh".
     *
     * @return array
     */
    protected function migrateFreshUsing()
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
     *
     * @return bool
     */
    protected function shouldDropViews()
    {
        return property_exists($this, 'dropViews') ? $this->dropViews : false;
    }

    /**
     * Determine if the seed task should be run when refreshing the database.
     *
     * @return bool
     */
    protected function shouldSeed()
    {
        return property_exists($this, 'seed') ? $this->seed : false;
    }

    /**
     * Determine the specific seeder class that should be used when refreshing the database.
     *
     * @return mixed
     */
    protected function seeder()
    {
        return property_exists($this, 'seeder') ? $this->seeder : false;
    }
}
