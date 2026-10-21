<?php

declare(strict_types=1);

namespace Hypervel\Tests\View\Blade;

class BladeEndSectionsTest extends AbstractBladeTestCase
{
    public function testEndSectionsAreCompiled(): void
    {
        $this->assertSame('<?php $__env->stopSection(); ?>', $this->compiler->compileString('@endsection'));
    }
}
