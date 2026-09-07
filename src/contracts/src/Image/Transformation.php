<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Image;

/**
 * Image transformations must be immutable because cloned pipelines share their instances.
 */
interface Transformation
{
}
