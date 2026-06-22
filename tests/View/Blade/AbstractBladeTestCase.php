<?php

declare(strict_types=1);

namespace Hypervel\Tests\View\Blade;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Tests\TestCase;
use Hypervel\View\Compilers\BladeCompiler;
use Mockery as m;

abstract class AbstractBladeTestCase extends TestCase
{
    protected BladeCompiler $compiler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->compiler = new BladeCompiler($this->getFiles(), __DIR__);
    }

    protected function getFiles(): Filesystem
    {
        return m::mock(Filesystem::class);
    }
}
