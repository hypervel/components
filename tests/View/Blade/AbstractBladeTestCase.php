<?php

declare(strict_types=1);

namespace Hypervel\Tests\View\Blade;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Tests\TestCase;
use Hypervel\View\Compilers\BladeCompiler;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

abstract class AbstractBladeTestCase extends TestCase
{
    use VerifiesDoubles;

    protected BladeCompiler $compiler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->compiler = new BladeCompiler($this->getFiles(), __DIR__);
    }

    /**
     * Create the compiler's filesystem double.
     */
    protected function getFiles(): Filesystem
    {
        return Double::for(Filesystem::class)->strict();
    }
}
