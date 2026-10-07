<?php

declare(strict_types=1);

namespace Hypervel\Tests\View\Blade;

class BladePhpStatementsTest extends AbstractBladeTestCase
{
    public function testPhpStatementsWithExpressionAreCompiled(): void
    {
        $string = '@php($set = true)';
        $expected = '<?php ($set = true); ?>';
        $this->assertEquals($expected, $this->compiler->compileString($string));
    }

    public function testStringWithParenthesisWithEndPHP(): void
    {
        $string = "@php(\$data = ['related_to' => 'issue#45388'];) {{ \$data }} @endphp";
        $expected = "<?php(\$data = ['related_to' => 'issue#45388'];) {{ \$data }} ?>";

        $this->assertEquals($expected, $this->compiler->compileString($string));
    }

    public function testPhpStatementsWithoutExpressionAreIgnored(): void
    {
        $string = '@php';
        $expected = '@php';
        $this->assertEquals($expected, $this->compiler->compileString($string));

        $string = '{{ "Ignore: @php" }}';
        $expected = '<?php echo e("Ignore: @php"); ?>';
        $this->assertEquals($expected, $this->compiler->compileString($string));
    }

    public function testPhpStatementsDontParseBladeCode(): void
    {
        $string = '@php echo "{{ This is a blade tag }}" @endphp';
        $expected = '<?php echo "{{ This is a blade tag }}" ?>';
        $this->assertEquals($expected, $this->compiler->compileString($string));
    }

    public function testVerbatimAndPhpStatementsDontGetMixedUp(): void
    {
        $string = "@verbatim {{ Hello, I'm not blade! }}"
                . "\n@php echo 'And I'm not PHP!' @endphp"
                . "\n@endverbatim {{ 'I am Blade' }}"
                . "\n@php echo 'I am PHP {{ not Blade }}' @endphp";

        $expected = " {{ Hello, I'm not blade! }}"
                . "\n@php echo 'And I'm not PHP!' @endphp"
                . "\n <?php echo e('I am Blade'); ?>"
                . "\n\n<?php echo 'I am PHP {{ not Blade }}' ?>";

        $this->assertEquals($expected, $this->compiler->compileString($string));
    }

    public function testStringWithOpeningParenthesisCanBeCompiled(): void
    {
        $string = "@php(\$data = ['single' => ':(('])";
        $expected = "<?php (\$data = ['single' => ':((']); ?>";
        $this->assertEquals($expected, $this->compiler->compileString($string));

        $string = "@php(\$data = ['single' => (string)':(('])";
        $expected = "<?php (\$data = ['single' => (string)':((']); ?>";
        $this->assertEquals($expected, $this->compiler->compileString($string));

        $string = "@php(\$data = ['single' => '(()(('])";
        $expected = "<?php (\$data = ['single' => '(()((']); ?>";
        $this->assertEquals($expected, $this->compiler->compileString($string));
    }

    public function testStringWithParenthesisCanBeCompiled(): void
    {
        $string = "@php(\$data = ['single' => ')'])";
        $expected = "<?php (\$data = ['single' => ')']); ?>";

        $this->assertEquals($expected, $this->compiler->compileString($string));

        $string = "@php(\$data = ['(multiple)-))' => '((-))'])";
        $expected = "<?php (\$data = ['(multiple)-))' => '((-))']); ?>";

        $this->assertEquals($expected, $this->compiler->compileString($string));

        $string = "@php(\$data = [(int)'(multiple)-))' => (bool)'((casty))'])";
        $expected = "<?php (\$data = [(int)'(multiple)-))' => (bool)'((casty))']); ?>";

        $this->assertEquals($expected, $this->compiler->compileString($string));

        $this->assertSame('<?php echo $__env->renderEach(\'foo\', \'b)a)r\'); ?>', $this->compiler->compileString('@each(\'foo\', \'b)a)r\')'));
        $this->assertSame('<?php echo $__env->make(\'test_for\', [\'issue))\' => \'(issue#45424))\'], array_diff_key(get_defined_vars(), [\'__data\' => 1, \'__path\' => 1]))->render(); ?>', $this->compiler->compileString('@include(\'test_for\', [\'issue))\' => \'(issue#45424))\'])'));
        $this->assertSame('( <?php echo $__env->make(\'test_for\', [\'not_too_much))\' => \'(issue#45424))\'], array_diff_key(get_defined_vars(), [\'__data\' => 1, \'__path\' => 1]))->render(); ?>))', $this->compiler->compileString('( @include(\'test_for\', [\'not_too_much))\' => \'(issue#45424))\'])))'));
    }

    public function testStringWithEmptyStringDataValue(): void
    {
        $string = "@php(\$data = ['test' => ''])";

        $expected = "<?php (\$data = ['test' => '']); ?>";

        $this->assertEquals($expected, $this->compiler->compileString($string));

        $string = "@php(\$data = ['test' => \"\"])";

        $expected = "<?php (\$data = ['test' => \"\"]); ?>";

        $this->assertEquals($expected, $this->compiler->compileString($string));
    }

    public function testStringWithEscapingDataValue(): void
    {
        $string = "@php(\$data = ['test' => 'won\\'t break'])";

        $expected = "<?php (\$data = ['test' => 'won\\'t break']); ?>";

        $this->assertEquals($expected, $this->compiler->compileString($string));

        $string = "@php(\$data = ['test' => \"\\\"escaped\\\"\"])";

        $expected = "<?php (\$data = ['test' => \"\\\"escaped\\\"\"]); ?>";

        $this->assertEquals($expected, $this->compiler->compileString($string));
    }

    public function testUnclosedParenthesisForBladeTags(): void
    {
        $string = "<span @class(['(']></span>";
        $expected = "<span class=\"<?php echo \\Hypervel\\Support\\Arr::toCssClasses([]); ?>\"(['(']></span>";

        $this->assertEquals($expected, $this->compiler->compileString($string));

        $string = "<span @class(['']></span>";
        $expected = "<span class=\"<?php echo \\Hypervel\\Support\\Arr::toCssClasses([]); ?>\"(['']></span>";

        $this->assertEquals($expected, $this->compiler->compileString($string));

        $string = "<span @class([')']></span>";
        $expected = "<span @class([')']></span>";

        $this->assertEquals($expected, $this->compiler->compileString($string));

        $string = "<span @class(['))']></span>";
        $expected = "<span @class(['))']></span>";

        $this->assertEquals($expected, $this->compiler->compileString($string));
    }

    public function testNestedTagCalls(): void
    {
        $string = "<span @class(['k' => @empty(\$v)])></span>";
        $expected = '<span class="<?php echo \Hypervel\Support\Arr::toCssClasses([\'k\' => @empty($v)]); ?>"></span>';
        $this->assertEquals($expected, $this->compiler->compileString($string));

        $string = "<span @class(['k))' => @empty(\$v)])></span>";
        $expected = '<span class="<?php echo \Hypervel\Support\Arr::toCssClasses([\'k))\' => @empty($v)]); ?>"></span>';
        $this->assertEquals($expected, $this->compiler->compileString($string));

        $string = "<span @class(['k' => @empty(\$v), 't' => @empty(\$v1)])></span>";
        $expected = '<span class="<?php echo \Hypervel\Support\Arr::toCssClasses([\'k\' => @empty($v), \'t\' => @empty($v1)]); ?>"></span>';
        $this->assertEquals($expected, $this->compiler->compileString($string));

        $string = "<span @class(['k' => @empty(\$v), 't' => @empty(\$v1)])></span>";
        $expected = '<span class="<?php echo \Hypervel\Support\Arr::toCssClasses([\'k\' => @empty($v), \'t\' => @empty($v1)]); ?>"></span>';
        $this->assertEquals($expected, $this->compiler->compileString($string));

        $string = "<span @class(['k' => @empty(\$v), 't' => @empty(\$v1), 'r' => @empty(\$v2)])></span>";
        $expected = '<span class="<?php echo \Hypervel\Support\Arr::toCssClasses([\'k\' => @empty($v), \'t\' => @empty($v1), \'r\' => @empty($v2)]); ?>"></span>';
        $this->assertEquals($expected, $this->compiler->compileString($string));

        $string = "<span @class(['k' => @empty(\$v), 't))' => @empty(\$v1), 'r' => @empty(\$v2)])></span>";
        $expected = '<span class="<?php echo \Hypervel\Support\Arr::toCssClasses([\'k\' => @empty($v), \'t))\' => @empty($v1), \'r\' => @empty($v2)]); ?>"></span>';
        $this->assertEquals($expected, $this->compiler->compileString($string));

        $string = "<span @class(['k' => @empty(\$v), 't' => @empty(\$v1), 'r' => @empty(\$v2), 'l' => 'l'])></span><span @class(['k' => @empty(\$v)])></span>";
        $expected = '<span class="<?php echo \Hypervel\Support\Arr::toCssClasses([\'k\' => @empty($v), \'t\' => @empty($v1), \'r\' => @empty($v2), \'l\' => \'l\']); ?>"></span><span class="<?php echo \Hypervel\Support\Arr::toCssClasses([\'k\' => @empty($v)]); ?>"></span>';
        $this->assertEquals($expected, $this->compiler->compileString($string));
    }

    public function testItDoesNotCompileInvalidSyntax(): void
    {
        $template = "<a @class(['k\\' => ()])></a>";
        $this->assertEquals($template, $this->compiler->compileString($template));
    }
}
