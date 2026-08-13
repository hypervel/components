<?php

declare(strict_types=1);

namespace Hypervel\Image\Drivers;

use Intervention\Image\Drivers\Imagick\Driver as InterventionImagickDriver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageManagerInterface;

class ImagickDriver extends InterventionDriver
{
    /**
     * Create the underlying Intervention image manager.
     */
    protected function createManager(): ImageManagerInterface
    {
        return ImageManager::usingDriver(InterventionImagickDriver::class);
    }
}
