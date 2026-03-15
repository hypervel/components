<?php

declare(strict_types=1);

namespace Hypervel\Tests\View\Blade;

use Hypervel\Filesystem\Filesystem;
use Hypervel\View\Compilers\BladeCompiler;
use Mockery as m;
use PHPUnit\Framework\TestCase;

abstract class AbstractBladeTestCase extends TestCase
{
    /**
     * @var \Hypervel\View\Compilers\BladeCompiler
     */
    protected $compiler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->compiler = new BladeCompiler($this->getFiles(), __DIR__);
    }

    protected function getFiles()
    {
        return m::mock(Filesystem::class);
    }
}
