<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\View;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Support\Facades\Blade;
use Hypervel\Support\Facades\Config;
use Hypervel\Support\Facades\View;
use Hypervel\Testbench\TestCase;
use Hypervel\View\Component;
use Mockery as m;
use Override;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

use function Hypervel\Filesystem\join_paths;

class BladeTest extends TestCase
{
    #[Override]
    protected function tearDown(): void
    {
        $this->artisan('view:clear');

        parent::tearDown();
    }

    public function testRenderingBladeString(): void
    {
        $this->assertSame('Hello Taylor', Blade::render('Hello {{ $name }}', ['name' => 'Taylor']));
    }

    public function testRenderingBladeLongMaxpathlenString(): void
    {
        $longString = str_repeat('a', PHP_MAXPATHLEN);

        $result = Blade::render($longString . '{{ $name }}', ['name' => 'a']);

        $this->assertSame($longString . 'a', $result);
    }

    #[RunInSeparateProcess]
    public function testRenderingBladeLongMaxpathlenStringWithExactLength(): void
    {
        for ($i = PHP_MAXPATHLEN - 200; $i <= PHP_MAXPATHLEN + 1; ++$i) {
            $longString = str_repeat('x', $i);

            $result = Blade::render($longString);

            $this->assertSame($longString, $result);
        }
    }

    public function testRenderingBladeComponentInstance(): void
    {
        $component = new HelloComponent('Taylor');

        $this->assertSame('Hello Taylor', Blade::renderComponent($component));
    }

    public function testBasicBladeRendering(): void
    {
        $view = View::make('hello', ['name' => 'Taylor'])->render();

        $this->assertSame('Hello Taylor', trim($view));
    }

    public function testRenderingAComponent(): void
    {
        $view = View::make('uses-panel', ['name' => 'Taylor'])->render();

        $this->assertSame('<div class="ml-2">
    Hello Taylor
</div>', trim($view));
    }

    public function testRenderingADynamicComponent(): void
    {
        $view = View::make('uses-panel-dynamically', ['name' => 'Taylor'])->render();

        $this->assertSame('<div class="ml-2" wire:model="foo" wire:model.lazy="bar">
    Hello Taylor
</div>', trim($view));
    }

    public function testRenderingTheSameDynamicComponentWithDifferentAttributes(): void
    {
        $view = View::make('varied-dynamic-calls')->render();

        $this->assertSame('<span class="text-medium">
    Hello Taylor
</span>
<span >
    Hello Samuel
</span>', trim($view));
    }

    public function testInlineLinkTypeAttributesDontAddExtraSpacingAtEnd(): void
    {
        $view = View::make('uses-link')->render();

        $this->assertSame('This is a sentence with a <a href="https://hypervel.org">link</a>.', trim($view));
    }

    public function testAppendableAttributes(): void
    {
        $view = View::make('uses-appendable-panel', ['name' => 'Taylor', 'withInjectedValue' => true])->render();

        $this->assertSame('<div class="mt-4 bg-gray-100" data-controller="inside-controller outside-controller" foo="bar">
    Hello Taylor
</div>', trim($view));

        $view = View::make('uses-appendable-panel', ['name' => 'Taylor', 'withInjectedValue' => false])->render();

        $this->assertSame('<div class="mt-4 bg-gray-100" data-controller="inside-controller" foo="bar">
    Hello Taylor
</div>', trim($view));
    }

    public function testNestedAnonymousAttributeProxyingWorksCorrectly(): void
    {
        $view = View::make('uses-child-input')->render();

        $this->assertSame('<input class="disabled-class" foo="bar" type="text" disabled />', trim($view));
    }

    public function testConsumeDefaults(): void
    {
        $view = View::make('consume')->render();

        $this->assertSame('<h1>Menu</h1>
<div>Slot: A, Color: orange, Default: foo</div>
<div>Slot: B, Color: red, Default: foo</div>
<div>Slot: C, Color: blue, Default: foo</div>
<div>Slot: D, Color: red, Default: foo</div>
<div>Slot: E, Color: red, Default: foo</div>
<div>Slot: F, Color: yellow, Default: foo</div>', trim($view));
    }

    public function testConsumeWithProps(): void
    {
        $view = View::make('consume', ['color' => 'rebeccapurple'])->render();

        $this->assertSame('<h1>Menu</h1>
<div>Slot: A, Color: orange, Default: foo</div>
<div>Slot: B, Color: rebeccapurple, Default: foo</div>
<div>Slot: C, Color: blue, Default: foo</div>
<div>Slot: D, Color: rebeccapurple, Default: foo</div>
<div>Slot: E, Color: rebeccapurple, Default: foo</div>
<div>Slot: F, Color: yellow, Default: foo</div>', trim($view));
    }

    public function testNameAttributeCanBeUsedIfUsingShortSlotNames(): void
    {
        $content = Blade::render('<x-input-with-slot>
    <x-slot:input name="my_form_field" class="text-input-lg" data-test="data">Test</x-slot:input>
</x-input-with-slot>');

        $this->assertSame('<div>
    <input type="text" class="input text-input-lg" data-test="data" name="my_form_field" />
</div>', trim($content));
    }

    public function testNameAttributeCantBeUsedIfNotUsingShortSlotNames(): void
    {
        $content = Blade::render('<x-input-with-slot>
    <x-slot name="input" class="text-input-lg" data-test="data">Test</x-slot>
</x-input-with-slot>');

        $this->assertSame('<div>
    <input type="text" class="input text-input-lg" data-test="data" />
</div>', trim($content));
    }

    public function testBoundNameAttributeCanBeUsedIfUsingShortSlotNames(): void
    {
        $content = Blade::render('<x-input-with-slot>
    <x-slot:input :name="\'my_form_field\'" class="text-input-lg" data-test="data">Test</x-slot:input>
</x-input-with-slot>');

        $this->assertSame('<div>
    <input type="text" class="input text-input-lg" data-test="data" name="my_form_field" />
</div>', trim($content));
    }

    public function testBoundNameAttributeCanBeUsedIfUsingShortSlotNamesAndNotFirstAttribute(): void
    {
        $content = Blade::render('<x-input-with-slot>
    <x-slot:input class="text-input-lg" :name="\'my_form_field\'" data-test="data">Test</x-slot:input>
</x-input-with-slot>');

        $this->assertSame('<div>
    <input type="text" class="input text-input-lg" name="my_form_field" data-test="data" />
</div>', trim($content));
    }

    public function testNoNamePassedToSlotUsesDefaultName(): void
    {
        $content = Blade::render('<x-link href="#"><x-slot>default slot</x-slot></x-link>');

        $this->assertSame('<a href="#">default slot</a>', trim($content));
    }

    public function testViewCacheCommandHandlesConfiguredBladeExtensions(): void
    {
        View::addExtension('sh', 'blade');
        $this->artisan('view:cache');

        $compiledFiles = Finder::create()->in(Config::get('view.compiled'))->files();
        $found = collect($compiledFiles)
            ->contains(fn (SplFileInfo $file) => str_contains($file->getContents(), 'echo "<?php echo e($scriptMessage); ?>" > output.log'));
        $this->assertTrue($found);
    }

    public function testIncludeScopedDoesNotInheritParentScope(): void
    {
        // Regular @include passes parent scope variables
        $regularInclude = View::make('uses-include-regular', [
            'parentVar' => 'parent-value',
            'explicitVar' => 'explicit-value',
        ])->render();

        $this->assertSame('Parent: parent-value, Explicit: explicit-value', trim($regularInclude));

        // @includeIsolated does NOT pass parent scope variables
        $scopedInclude = View::make('uses-include-scoped', [
            'parentVar' => 'parent-value',
            'explicitVar' => 'explicit-value',
        ])->render();

        $this->assertSame('Parent: undefined, Explicit: explicit-value', trim($scopedInclude));
    }

    public function testViewCacheCommandDeduplicatesPathsBeforeCompiling(): void
    {
        View::addNamespace('templates', join_paths(__DIR__, 'templates'));
        View::addNamespace('components', join_paths(__DIR__, 'templates', 'components'));

        $compiler = m::mock(app('blade.compiler'))->makePartial();
        $compiler->shouldReceive('compile')->with(realpath(__DIR__ . '/templates/components/panel.blade.php'))->once();

        $this->instance('blade.compiler', $compiler);

        $this->artisan('view:cache');
    }

    #[Override]
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app['config']->set('view.paths', [__DIR__ . '/templates']);
    }
}

class HelloComponent extends Component
{
    public string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function render(): string
    {
        return 'Hello {{ $name }}';
    }
}
