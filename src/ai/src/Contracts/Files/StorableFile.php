<?php

declare(strict_types=1);

namespace Hypervel\Ai\Contracts\Files;

use Stringable;

interface StorableFile extends HasContent, HasMimeType, HasName, Stringable
{
}
