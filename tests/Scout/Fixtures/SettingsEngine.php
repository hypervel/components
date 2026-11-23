<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Fixtures;

use Hypervel\Scout\Contracts\UpdatesIndexSettings;
use Hypervel\Scout\Engines\Engine;

abstract class SettingsEngine extends Engine implements UpdatesIndexSettings
{
}
