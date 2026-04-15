<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Generators;

use Hypervel\Testbench\Concerns\InteractsWithPublishedFiles;

abstract class TestCase extends \Hypervel\Testbench\TestCase
{
    use InteractsWithPublishedFiles;
}
