<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Tools;

use Hypervel\Ai\Contracts\Approvable;

abstract class ApprovableNamedTool extends NamedTool implements Approvable
{
}
