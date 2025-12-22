<?php

declare(strict_types=1);

namespace Hypervel\Tests\View\Blade;

/**
 * @internal
 * @coversNothing
 */
class BladeComponentFirstTest extends AbstractBladeTestCase
{
    public function testComponentFirstsAreCompiled()
    {
        $this->assertSame('<?php $__env->startComponentFirst(["one", "two"]); ?>', $this->compiler->compileString('@componentFirst(["one", "two"])'));
        $this->assertSame('<?php $__env->startComponentFirst(["one", "two"], ["foo" => "bar"]); ?>', $this->compiler->compileString('@componentFirst(["one", "two"], ["foo" => "bar"])'));
    }
}
