<?php

declare(strict_types=1);

namespace Hypervel\Tests\View\Blade;

use Hypervel\Support\Str;
use Symfony\Component\Uid\Uuid;

class BladePushTest extends AbstractBladeTestCase
{
    public function testPushIsCompiled(): void
    {
        $string = '@push(\'foo\')
test
@endpush';
        $expected = '<?php $__env->startPush(\'foo\'); ?>
test
<?php $__env->stopPush(); ?>';
        $this->assertEquals($expected, $this->compiler->compileString($string));
    }

    public function testPushIsCompiledWithParenthesis(): void
    {
        $string = '@push(\'foo):))\')
test
@endpush';
        $expected = '<?php $__env->startPush(\'foo):))\'); ?>
test
<?php $__env->stopPush(); ?>';
        $this->assertEquals($expected, $this->compiler->compileString($string));
    }

    public function testPushOnceIsCompiled(): void
    {
        $string = '@pushOnce(\'foo\', \'bar\')
test
@endPushOnce';

        $expected = '<?php if (! $__env->hasRenderedOnce(\'bar\')): $__env->markAsRenderedOnce(\'bar\');
$__env->startPush(\'foo\'); ?>
test
<?php $__env->stopPush(); endif; ?>';

        $this->assertEquals($expected, $this->compiler->compileString($string));
    }

    public function testPushOnceIsCompiledWhenIdIsMissing(): void
    {
        Str::createUuidsUsing(fn () => Uuid::fromString('e60e8f77-9ac3-4f71-9f8e-a044ef481d7f'));

        $string = '@pushOnce(\'foo\')
test
@endPushOnce';

        $expected = '<?php if (! $__env->hasRenderedOnce(\'e60e8f77-9ac3-4f71-9f8e-a044ef481d7f\')): $__env->markAsRenderedOnce(\'e60e8f77-9ac3-4f71-9f8e-a044ef481d7f\');
$__env->startPush(\'foo\'); ?>
test
<?php $__env->stopPush(); endif; ?>';

        $this->assertEquals($expected, $this->compiler->compileString($string));
    }

    public function testPushOnceCompilesCommaBearingStackWithExplicitId(): void
    {
        $expected = "<?php if (! \$__env->hasRenderedOnce('id')): \$__env->markAsRenderedOnce('id');\n"
            . "\$__env->startPush('body,end'); ?>";

        $this->assertSame($expected, $this->compiler->compileString("@pushOnce('body,end', 'id')"));
    }

    public function testPushOnceCompilesNestedCommaExpressionWithGeneratedId(): void
    {
        Str::createUuidsUsing(fn () => Uuid::fromString('e60e8f77-9ac3-4f71-9f8e-a044ef481d7f'));

        $expected = "<?php if (! \$__env->hasRenderedOnce('e60e8f77-9ac3-4f71-9f8e-a044ef481d7f')): "
            . "\$__env->markAsRenderedOnce('e60e8f77-9ac3-4f71-9f8e-a044ef481d7f');\n"
            . "\$__env->startPush(config('view.stack', 'fallback')); ?>";

        $this->assertSame($expected, $this->compiler->compileString("@pushOnce(config('view.stack', 'fallback'))"));
    }

    public function testPushIfIsCompiled(): void
    {
        $string = '@pushIf(true, \'foo\')
test
@endPushIf';
        $expected = '<?php if(true): $__env->startPush(\'foo\'); ?>
test
<?php $__env->stopPush(); endif; ?>';

        $this->assertEquals($expected, $this->compiler->compileString($string));
    }

    public function testPushIfWithMoreThanOneCommaIsCompiled(): void
    {
        $string = '@pushIf(Str::startsWith(\'abc\', \'a\'), \'body-end\')
test
@endPushIf';

        $expected = '<?php if(Str::startsWith(\'abc\', \'a\')): $__env->startPush(\'body-end\'); ?>
test
<?php $__env->stopPush(); endif; ?>';

        $this->assertEquals($expected, $this->compiler->compileString($string));
    }

    public function testPushIfWithCommaInStringIsCompiled(): void
    {
        $string = '@pushIf(Str::startsWith(\'abc,,,\', \'a,,,\'), \'body-end\')
test
@endPushIf';

        $expected = '<?php if(Str::startsWith(\'abc,,,\', \'a,,,\')): $__env->startPush(\'body-end\'); ?>
test
<?php $__env->stopPush(); endif; ?>';

        $this->assertEquals($expected, $this->compiler->compileString($string));
    }

    public function testElsePushIfWithMoreThanOneCommaIsCompiled(): void
    {
        $string = '@pushIf(true, \'body-end\')
if
@elsePushIf(Str::startsWith(\'abc\', \'a\'), \'body-end\')
elseif
@endPushIf';

        $expected = '<?php if(true): $__env->startPush(\'body-end\'); ?>
if
<?php $__env->stopPush(); elseif(Str::startsWith(\'abc\', \'a\')): $__env->startPush(\'body-end\'); ?>
elseif
<?php $__env->stopPush(); endif; ?>';

        $this->assertEquals($expected, $this->compiler->compileString($string));
    }

    public function testPushIfElseIsCompiled(): void
    {
        $string = '@pushIf(true, \'stack\')
if
@elsePushIf(false, \'stack\')
elseif
@elsePush(\'stack\')
else
@endPushIf';
        $expected = '<?php if(true): $__env->startPush(\'stack\'); ?>
if
<?php $__env->stopPush(); elseif(false): $__env->startPush(\'stack\'); ?>
elseif
<?php $__env->stopPush(); else: $__env->startPush(\'stack\'); ?>
else
<?php $__env->stopPush(); endif; ?>';

        $this->assertEquals($expected, $this->compiler->compileString($string));
    }

    public function testPushIfCompilesCommaBearingStackExpressions(): void
    {
        $this->assertSame(
            "<?php if(true): \$__env->startPush('body,end'); ?>",
            $this->compiler->compileString("@pushIf(true, 'body,end')")
        );

        $this->assertSame(
            "<?php \$__env->stopPush(); elseif(true): \$__env->startPush(config('view.stack', 'body,end')); ?>",
            $this->compiler->compileString("@elsePushIf(true, config('view.stack', 'body,end'))")
        );
    }

    public function testPushIfCompilesInterpolatedStringsBeforeTheStackArgument(): void
    {
        $this->assertSame(
            '<?php if($x === "{$b}"): $__env->startPush(\'stack\'); ?>',
            $this->compiler->compileString('@pushIf($x === "{$b}", \'stack\')')
        );
    }
}
