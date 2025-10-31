<?php

namespace Hypervel\Tests\View;

use Hypervel\Filesystem\Filesystem;
use Hypervel\View\Engines\PhpEngine;
use PHPUnit\Framework\TestCase;

class ViewPhpEngineTest extends TestCase
{
    public function testViewsMayBeProperlyRendered()
    {
        $engine = new PhpEngine(new Filesystem);
        $this->assertSame('Hello World' . PHP_EOL, $engine->get(__DIR__.'/fixtures/basic.php'));
    }
}
