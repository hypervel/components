<?php

declare(strict_types=1);

namespace Hypervel\Tests\View;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Tests\TestCase;
use Hypervel\View\Engines\PhpEngine;

class ViewPhpEngineTest extends TestCase
{
    public function testViewsMayBeProperlyRendered()
    {
        $engine = new PhpEngine(new Filesystem);
        $this->assertSame('Hello World' . PHP_EOL, $engine->get(__DIR__ . '/Fixtures/basic.php'));
    }
}
