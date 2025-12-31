<?php

declare(strict_types=1);

namespace Hypervel\Tests\View;

use Hypervel\Filesystem\Filesystem;
use Hypervel\View\Engines\PhpEngine;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class ViewPhpEngineTest extends TestCase
{
    public function testViewsMayBeProperlyRendered()
    {
        $engine = new PhpEngine(new Filesystem());
        $this->assertSame('Hello World' . PHP_EOL, $engine->get(__DIR__ . '/fixtures/basic.php'));
    }
}
