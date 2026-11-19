<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\View;

use Closure;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Facades\Blade;
use Hypervel\Support\Facades\View;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use Hypervel\View\Component;
use Hypervel\View\ComponentAttributeBag;
use Hypervel\View\DynamicComponent;
use Hypervel\View\RawTemplate;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use Override;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

use function Hypervel\Filesystem\join_paths;

class BladeTest extends TestCase
{
    use VerifiesDoubles;

    /**
     * Clear compiled views after the test.
     */
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

    public function testRenderingBladeStringDoesNotResolveOrDeleteAMatchingNamedView(): void
    {
        $files = new Filesystem;
        $directory = ParallelTesting::tempDir('BladeRawTemplateCollision');
        $viewPath = $directory . '/literal-collision.blade.php';

        try {
            $files->deleteDirectory($directory);
            $files->ensureDirectoryExists($directory);
            $files->put($viewPath, 'Named application view');
            View::addLocation($directory);

            $this->assertTrue(View::exists('literal-collision'));
            $this->assertSame('literal-collision', Blade::render('literal-collision', deleteCachedView: true));
            $this->assertTrue($files->exists($viewPath));
            $this->assertSame('Named application view', View::make('literal-collision')->render());
        } finally {
            $files->deleteDirectory($directory);
        }
    }

    #[DefineEnvironment('withViewCacheEnabled')]
    public function testRenderingAndDeletingSameBladeStringTwiceWithViewCacheEnabled(): void
    {
        $this->assertSame('Hello Taylor', Blade::render('Hello {{ $name }}', ['name' => 'Taylor'], true));
        $this->assertSame('Hello Taylor', Blade::render('Hello {{ $name }}', ['name' => 'Taylor'], true));
    }

    #[DefineEnvironment('withViewCacheDisabled')]
    public function testRenderingAndDeletingSameBladeStringTwiceWithViewCacheDisabled(): void
    {
        $this->assertSame('Hello Taylor', Blade::render('Hello {{ $name }}', ['name' => 'Taylor'], true));
        $this->assertSame('Hello Taylor', Blade::render('Hello {{ $name }}', ['name' => 'Taylor'], true));
    }

    #[DefineEnvironment('withViewCacheDisabled')]
    public function testDeletingARenderedBladeStringDoesNotBreakOtherRendersOfTheSameTemplate(): void
    {
        $component = new HelloComponent('Taylor');
        $compiledFiles = fn (): array => scandir(config('view.compiled'));

        $this->assertSame('Hello Taylor', Blade::renderComponent($component));
        $files = $compiledFiles();
        $this->assertSame('Hello Ana', Blade::render('Hello {{ $name }}', ['name' => 'Ana'], true));
        $this->assertSame('Hello Taylor', Blade::renderComponent($component));

        $overlapped = false;
        View::composer('__components::*', function () use (&$overlapped): void {
            if (! $overlapped) {
                $overlapped = true;
                $this->assertSame('Hello Ben', Blade::render('Hello {{ $name }}', ['name' => 'Ben'], true));
            }
        });

        $this->assertSame('Hello Ana', Blade::render('Hello {{ $name }}', ['name' => 'Ana'], true));
        $this->assertTrue($overlapped);
        $this->assertSame($files, $compiledFiles());
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

    public function testRenderingRawTemplateComponentInstance(): void
    {
        $component = new RawTemplateHelloComponent('Taylor');

        $this->assertSame('Hello Taylor', Blade::renderComponent($component));
    }

    public function testRenderingRawTemplateClosureComponentInstance(): void
    {
        $component = new RawTemplateClosureComponent('Taylor');

        $this->assertSame('Hello Taylor', Blade::renderComponent($component));
    }

    public function testRawTemplateIsNeverResolvedAsAViewName(): void
    {
        $this->assertTrue(View::exists('hello'));

        $component = new RawTemplateViewNameComponent;

        $this->assertSame('hello', Blade::renderComponent($component));
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

    public function testDynamicComponentSlotAttributesAreNotCompiledAsBlade(): void
    {
        $payload = '{{ 7191 * 2 }}';

        $static = Blade::render('<x-input-with-slot>
    <x-slot:input :data-x="$payload">Test</x-slot:input>
</x-input-with-slot>', ['payload' => $payload]);

        $dynamic = Blade::render('<x-dynamic-component component="input-with-slot">
    <x-slot:input :data-x="$payload">Test</x-slot:input>
</x-dynamic-component>', ['payload' => $payload]);

        $this->assertStringContainsString('data-x="{{ 7191 * 2 }}"', $static);
        $this->assertStringNotContainsString('14382', $dynamic);
        $this->assertSame(trim($static), trim($dynamic));
    }

    public function testDynamicComponentRendersBoundAttributesLikeClassComponentTag(): void
    {
        Blade::component('attributes-hello', AttributesHelloComponent::class);
        DynamicComponent::flushState();

        $data = [
            'name' => 'Taylor & Co',
            'title' => '"Quotes" & <tags>',
            'bag' => new ComponentAttributeBag(['class' => 'a&amp;b']),
        ];

        foreach ([':title="$title"', 'title="{{ $title }}"', ':class="$title" :attributes="$bag"'] as $attributes) {
            $static = Blade::render('<x-attributes-hello :name="$name" ' . $attributes . ' />', $data);
            $dynamic = Blade::render('<x-dynamic-component component="attributes-hello" :name="$name" ' . $attributes . ' />', $data);

            $this->assertSame(trim($static), trim($dynamic));
        }

        $this->assertSame(
            '<div title="&quot;Quotes&quot; &amp; &lt;tags&gt;">Hello Taylor &amp; Co</div>',
            trim(Blade::render('<x-dynamic-component component="attributes-hello" :name="$name" :title="$title" />', $data))
        );
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

        $compiledFiles = Finder::create()->in(config('view.compiled'))->files();
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

        // @includeIsolated does not pass parent scope variables.
        $scopedInclude = View::make('uses-include-scoped', [
            'parentVar' => 'parent-value',
            'explicitVar' => 'explicit-value',
        ])->render();

        $this->assertSame('Parent: undefined, Explicit: explicit-value', trim($scopedInclude));
    }

    public function testViewCacheCommandDeduplicatesPathsBeforeCompiling(): void
    {
        View::addNamespace('templates', join_paths(__DIR__, 'Fixtures', 'templates'));
        View::addNamespace('components', join_paths(__DIR__, 'Fixtures', 'templates', 'components'));

        $path = realpath(__DIR__ . '/Fixtures/templates/components/panel.blade.php');
        $compiler = Double::for(Blade::getFacadeRoot())->passthru();
        Blade::swap($compiler);

        $this->artisan('view:cache');

        $compiler->received('compile')->with($path)->times(1);
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
    public function testViewCacheCommandCompilesNamespacedViewsAtThePathTheyRenderFrom(): void
    {
        View::addNamespace('components', join_paths(__DIR__, 'Fixtures', 'templates', 'components', '..', 'components'));

        $this->artisan('view:cache');

        $this->assertFileExists(Blade::getCompiledPath(View::getFinder()->find('components::panel')));
    }

    /**
     * Configure the Blade fixture templates.
     */
    #[Override]
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app->make('config')->set('view.paths', [__DIR__ . '/Fixtures/templates']);
    }

    /**
     * Enable compiled view caching.
     */
    protected function withViewCacheEnabled(ApplicationContract $app): void
    {
        $app->make('config')->set('view.cache', true);
    }

    /**
     * Disable compiled view caching.
     */
    protected function withViewCacheDisabled(ApplicationContract $app): void
    {
        $app->make('config')->set('view.cache', false);
    }
}

class HelloComponent extends Component
{
    public string $name;

    /**
     * Create a greeting component.
     */
    public function __construct(string $name)
    {
        $this->name = $name;
    }

    /**
     * Get the greeting template.
     */
    public function render(): string
    {
        return 'Hello {{ $name }}';
    }
}

class AttributesHelloComponent extends Component
{
    /**
     * Create a greeting component.
     */
    public function __construct(public string $name)
    {
    }

    /**
     * Get the greeting template with the component attributes.
     */
    public function render(): string
    {
        return '<div {{ $attributes }}>Hello {{ $name }}</div>';
    }
}

class RawTemplateHelloComponent extends Component
{
    /**
     * Create a greeting component.
     */
    public function __construct(public string $name)
    {
    }

    /**
     * Get the greeting template.
     */
    public function render(): RawTemplate
    {
        return new RawTemplate('Hello {{ $name }}');
    }
}

class RawTemplateClosureComponent extends Component
{
    /**
     * Create a greeting component.
     */
    public function __construct(public string $name)
    {
    }

    /**
     * Get a closure that returns the greeting template.
     */
    public function render(): Closure
    {
        return function (array $data): RawTemplate {
            return new RawTemplate('Hello {{ $name }}');
        };
    }
}

class RawTemplateViewNameComponent extends Component
{
    /**
     * Get a template that matches an application view name.
     */
    public function render(): RawTemplate
    {
        return new RawTemplate('hello');
    }
}
