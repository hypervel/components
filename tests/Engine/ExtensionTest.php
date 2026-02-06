<?php

declare(strict_types=1);

namespace Hypervel\Tests\Engine;

use Hypervel\Engine\Extension;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class ExtensionTest extends TestCase
{
    use RunTestsInCoroutine;

    public function testExtensionLoaded()
    {
        $this->assertTrue(Extension::isLoaded());
    }
}
