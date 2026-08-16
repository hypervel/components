<?php

declare(strict_types=1);

namespace Hypervel\Image\Drivers;

use Intervention\Image\Drivers\Gd\Driver as InterventionGdDriver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageManagerInterface;

class GdDriver extends InterventionDriver
{
    /**
     * Create the underlying Intervention image manager.
     */
    protected function createManager(): ImageManagerInterface
    {
        return ImageManager::usingDriver(InterventionGdDriver::class);
    }
}
