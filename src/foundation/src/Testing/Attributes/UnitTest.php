<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Attributes;

use Attribute;

/**
 * Run a test without configuring the Hypervel framework.
 */
#[Attribute(Attribute::TARGET_METHOD)]
class UnitTest
{
}
