<?php

declare(strict_types=1);

namespace Hypervel\Tests\Engine;

use Hypervel\Engine\Extension;

/**
 * @internal
 * @coversNothing
 */
class ExtensionTest extends EngineTestCase
{
    public function testExtensionLoaded()
    {
        $this->assertTrue(Extension::isLoaded());
    }
}
