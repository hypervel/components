<?php

namespace Hypervel\Tests\View\Blade;

use Hypervel\Support\Str;
use Mockery;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidFactoryInterface;
use Ramsey\Uuid\UuidInterface;

class BladePrependTest extends AbstractBladeTestCase
{
    public function testPrependIsCompiled()
    {
        $string = '@prepend(\'foo\')
bar
@endprepend';
        $expected = '<?php $__env->startPrepend(\'foo\'); ?>
bar
<?php $__env->stopPrepend(); ?>';

        $this->assertEquals($expected, $this->compiler->compileString($string));
    }

    public function testPrependOnceIsCompiled()
    {
        $string = '@prependOnce(\'foo\', \'bar\')
test
@endPrependOnce';

        $expected = '<?php if (! $__env->hasRenderedOnce(\'bar\')): $__env->markAsRenderedOnce(\'bar\');
$__env->startPrepend(\'foo\'); ?>
test
<?php $__env->stopPrepend(); endif; ?>';

        $this->assertEquals($expected, $this->compiler->compileString($string));
    }

    public function testPrependOnceIsCompiledWhenIdIsMissing()
    {
        $uuid = Mockery::mock(UuidInterface::class);
        $uuid->shouldReceive('__toString')->andReturn('e60e8f77-9ac3-4f71-9f8e-a044ef481d7f');
        $factory = Mockery::mock(UuidFactoryInterface::class);
        $factory->shouldReceive('uuid4')->andReturn($uuid);
        Uuid::setFactory($factory);

        $string = '@prependOnce(\'foo\')
test
@endPrependOnce';

        $expected = '<?php if (! $__env->hasRenderedOnce(\'e60e8f77-9ac3-4f71-9f8e-a044ef481d7f\')): $__env->markAsRenderedOnce(\'e60e8f77-9ac3-4f71-9f8e-a044ef481d7f\');
$__env->startPrepend(\'foo\'); ?>
test
<?php $__env->stopPrepend(); endif; ?>';

        $this->assertEquals($expected, $this->compiler->compileString($string));
    }
}
