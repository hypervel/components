<?php

namespace Hypervel\Tests\View\Blade;

use Exception;
use Hypervel\Container\DefinitionSource;
use Hypervel\Context\ApplicationContext;
use Hypervel\Foundation\Application;
use Hypervel\Support\Fluent;
use Hypervel\Support\Stringable;
use PHPUnit\Framework\Attributes\DataProvider;

class BladeEchoHandlerTest extends AbstractBladeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->compiler->stringable(function (Fluent $object) {
            return 'Hello World';
        });
    }

    public function testBladeHandlerCanInterceptRegularEchos()
    {
        $this->assertSame(
            "<?php \$__bladeCompiler = app('blade.compiler'); ?><?php echo e(\$__bladeCompiler->applyEchoHandler(\$exampleObject)); ?>",
            $this->compiler->compileString('{{$exampleObject}}')
        );
    }

    public function testBladeHandlerCanInterceptRawEchos()
    {
        $this->assertSame(
            "<?php \$__bladeCompiler = app('blade.compiler'); ?><?php echo \$__bladeCompiler->applyEchoHandler(\$exampleObject); ?>",
            $this->compiler->compileString('{!!$exampleObject!!}')
        );
    }

    public function testBladeHandlerCanInterceptEscapedEchos()
    {
        $this->assertSame(
            "<?php \$__bladeCompiler = app('blade.compiler'); ?><?php echo e(\$__bladeCompiler->applyEchoHandler(\$exampleObject)); ?>",
            $this->compiler->compileString('{{{$exampleObject}}}')
        );
    }

    public function testWhitespaceIsPreservedCorrectly()
    {
        $this->assertSame(
            "<?php \$__bladeCompiler = app('blade.compiler'); ?><?php echo e(\$__bladeCompiler->applyEchoHandler(\$exampleObject)); ?>\n\n",
            $this->compiler->compileString("{{\$exampleObject}}\n")
        );
    }

    #[DataProvider('handlerLogicDataProvider')]
    public function testHandlerLogicWorksCorrectly($blade)
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('The fluent object has been successfully handled!');

        $this->compiler->stringable(Fluent::class, function ($object) {
            throw new Exception('The fluent object has been successfully handled!');
        });


        $app = $this->createApplication();
        $app->instance('blade.compiler', $this->compiler);

        $exampleObject = new Fluent();

        eval((new Stringable($this->compiler->compileString($blade)))->remove(['<?php', '?>']));
    }

    protected function createApplication()
    {
        $container = new Application(
            new DefinitionSource([]),
            'bath_path',
        );
        ApplicationContext::setContainer($container);

        return $container;
    }

    public static function handlerLogicDataProvider()
    {
        return [
            ['{{$exampleObject}}'],
            ['{{$exampleObject;}}'],
            ['{{{$exampleObject;}}}'],
            ['{!!$exampleObject;!!}'],
        ];
    }

    #[DataProvider('handlerWorksWithIterableDataProvider')]
    public function testHandlerWorksWithIterables($blade, $closure, $expectedOutput)
    {
        $this->compiler->stringable('iterable', $closure);

        $app = $this->createApplication();
        $app->instance('blade.compiler', $this->compiler);

        ob_start();
        eval((new Stringable($this->compiler->compileString($blade)))->remove(['<?php', '?>']));
        $output = ob_get_contents();
        ob_end_clean();

        $this->assertSame($expectedOutput, $output);
    }

    public static function handlerWorksWithIterableDataProvider()
    {
        return [
            [
                '{{[1,"two",3]}}',
                fn (iterable $arr) => implode(', ', $arr),
                '1, two, 3'
            ],
        ];
    }

    #[DataProvider('nonStringableDataProvider')]
    public function testHandlerWorksWithNonStringables($blade, $expectedOutput)
    {
        $app = $this->createApplication();
        $app->instance('blade.compiler', $this->compiler);

        ob_start();
        eval((new Stringable($this->compiler->compileString($blade)))->remove(['<?php', '?>']));
        $output = ob_get_contents();
        ob_end_clean();

        $this->assertSame($expectedOutput, $output);
    }

    public static function nonStringableDataProvider()
    {
        return [
            ['{{"foo" . "bar"}}', 'foobar'],
            ['{{ 1 + 2 }}{{ "test"; }}', '3test'],
            ['@php($test = "hi"){{ $test }}', 'hi'],
            ['{!! "&nbsp;" !!}', '&nbsp;'],
        ];
    }
}
