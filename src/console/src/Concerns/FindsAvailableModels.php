<?php

declare(strict_types=1);

namespace Hypervel\Console\Concerns;

use Hypervel\Support\Collection;
use Symfony\Component\Finder\Finder;

trait FindsAvailableModels
{
    /**
     * Get a list of possible model names.
     *
     * @return array<int, string>
     */
    protected function findAvailableModels(): array
    {
        $modelPath = is_dir(app_path('Models')) ? app_path('Models') : app_path();

        return (new Collection(Finder::create()->files()->depth(0)->in($modelPath)))
            ->map(fn ($file) => $file->getBasename('.php'))
            ->sort()
            ->values()
            ->all();
    }
}
