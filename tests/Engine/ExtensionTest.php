<?php

declare(strict_types=1);

namespace Hypervel\Tests\Engine;

use Hypervel\Engine\Extension;
use Hypervel\Tests\TestCase;

class ExtensionTest extends TestCase
{
    public function testExtensionLoaded()
    {
        $this->assertTrue(Extension::isLoaded());
    }
}
