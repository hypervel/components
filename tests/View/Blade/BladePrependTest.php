<?php

declare(strict_types=1);

namespace Hypervel\Tests\View\Blade;

use Hypervel\Support\Str;
use Symfony\Component\Uid\Uuid;

class BladePrependTest extends AbstractBladeTestCase
{
    public function testPrependIsCompiled(): void
    {
        $string = '@prepend(\'foo\')
bar
@endprepend';
        $expected = '<?php $__env->startPrepend(\'foo\'); ?>
bar
<?php $__env->stopPrepend(); ?>';

        $this->assertEquals($expected, $this->compiler->compileString($string));
    }

    public function testPrependOnceIsCompiled(): void
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

    public function testPrependOnceIsCompiledWhenIdIsMissing(): void
    {
        Str::createUuidsUsing(fn () => Uuid::fromString('e60e8f77-9ac3-4f71-9f8e-a044ef481d7f'));

        $string = '@prependOnce(\'foo\')
test
@endPrependOnce';

        $expected = '<?php if (! $__env->hasRenderedOnce(\'e60e8f77-9ac3-4f71-9f8e-a044ef481d7f\')): $__env->markAsRenderedOnce(\'e60e8f77-9ac3-4f71-9f8e-a044ef481d7f\');
$__env->startPrepend(\'foo\'); ?>
test
<?php $__env->stopPrepend(); endif; ?>';

        $this->assertEquals($expected, $this->compiler->compileString($string));
    }

    public function testPrependOnceCompilesCommaBearingStackWithExplicitId(): void
    {
        $expected = "<?php if (! \$__env->hasRenderedOnce('id')): \$__env->markAsRenderedOnce('id');\n"
            . "\$__env->startPrepend('body,end'); ?>";

        $this->assertSame($expected, $this->compiler->compileString("@prependOnce('body,end', 'id')"));
    }

    public function testPrependOnceCompilesNestedCommaExpressionWithGeneratedId(): void
    {
        Str::createUuidsUsing(fn () => Uuid::fromString('e60e8f77-9ac3-4f71-9f8e-a044ef481d7f'));

        $expected = "<?php if (! \$__env->hasRenderedOnce('e60e8f77-9ac3-4f71-9f8e-a044ef481d7f')): "
            . "\$__env->markAsRenderedOnce('e60e8f77-9ac3-4f71-9f8e-a044ef481d7f');\n"
            . "\$__env->startPrepend(config('view.stack', 'fallback')); ?>";

        $this->assertSame($expected, $this->compiler->compileString("@prependOnce(config('view.stack', 'fallback'))"));
    }
}
