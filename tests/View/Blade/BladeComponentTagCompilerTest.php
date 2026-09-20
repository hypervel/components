<?php

declare(strict_types=1);

namespace Hypervel\Tests\View\Blade;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\Support\Htmlable;
use Hypervel\Contracts\View\Factory;
use Hypervel\Contracts\View\View;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Application;
use Hypervel\Pagination\Paginator;
use Hypervel\View\Compilers\BladeCompiler;
use Hypervel\View\Compilers\ComponentTagCompiler;
use Hypervel\View\Component;
use Hypervel\View\ComponentAttributeBag;
use Hypervel\View\Factory as ViewFactory;
use InvalidArgumentException;
use Mockery as m;
use Stringable;

class BladeComponentTagCompilerTest extends AbstractBladeTestCase
{
    public function testSlotsCanBeCompiled()
    {
        $this->mockViewFactory();
        $result = $this->compiler()->compileSlots('<x-slot name="foo">
</x-slot>');

        $this->assertSame(
            "@slot('foo', null, []) \n" . ' @endslot',
            str_replace("\r\n", "\n", trim($result))
        );
    }

    public function testUnnamedSlotsUseTheDefaultSlotName(): void
    {
        $this->mockViewFactory();
        $result = $this->compiler()->compileSlots('<x-slot>
</x-slot>');

        $this->assertSame(
            "@slot('slot', null, []) \n" . ' @endslot',
            str_replace("\r\n", "\n", trim($result))
        );
    }

    public function testInlineSlotsCanBeCompiled()
    {
        $this->mockViewFactory();
        $result = $this->compiler()->compileSlots('<x-slot:foo>
</x-slot>');

        $this->assertSame(
            "@slot('foo', null, []) \n" . ' @endslot',
            str_replace("\r\n", "\n", trim($result))
        );
    }

    public function testDynamicSlotsCanBeCompiled()
    {
        $this->mockViewFactory();
        $result = $this->compiler()->compileSlots('<x-slot :name="$foo">
</x-slot>');

        $this->assertSame(
            "@slot(\$foo, null, []) \n" . ' @endslot',
            str_replace("\r\n", "\n", trim($result))
        );
    }

    public function testDynamicSlotsCanBeCompiledWithKeyOfObjects()
    {
        $this->mockViewFactory();
        $result = $this->compiler()->compileSlots('<x-slot :name="$foo->name">
</x-slot>');

        $this->assertSame(
            "@slot(\$foo->name, null, []) \n" . ' @endslot',
            str_replace("\r\n", "\n", trim($result))
        );
    }

    public function testSlotsWithAttributesCanBeCompiled()
    {
        $this->mockViewFactory();
        $result = $this->compiler()->compileSlots('<x-slot name="foo" class="font-bold">
</x-slot>');

        $this->assertSame(
            "@slot('foo', null, ['class' => 'font-bold']) \n" . ' @endslot',
            str_replace("\r\n", "\n", trim($result))
        );
    }

    public function testInlineSlotsWithAttributesCanBeCompiled()
    {
        $this->mockViewFactory();
        $result = $this->compiler()->compileSlots('<x-slot:foo class="font-bold">
</x-slot>');

        $this->assertSame(
            "@slot('foo', null, ['class' => 'font-bold']) \n" . ' @endslot',
            str_replace("\r\n", "\n", trim($result))
        );
    }

    public function testSlotsWithDynamicAttributesCanBeCompiled()
    {
        $this->mockViewFactory();
        $result = $this->compiler()->compileSlots('<x-slot name="foo" :class="$classes">
</x-slot>');

        $this->assertSame(
            "@slot('foo', null, ['class' => \\Hypervel\\View\\Compilers\\BladeCompiler::sanitizeComponentAttribute(\$classes)]) \n" . ' @endslot',
            str_replace("\r\n", "\n", trim($result))
        );
    }

    public function testSlotsWithClassDirectiveCanBeCompiled()
    {
        $this->mockViewFactory();
        $result = $this->compiler()->compileSlots('<x-slot name="foo" @class($classes)>
</x-slot>');

        $this->assertSame(
            "@slot('foo', null, ['class' => \\Hypervel\\View\\Compilers\\BladeCompiler::sanitizeComponentAttribute(\\Hypervel\\Support\\Arr::toCssClasses(\$classes))]) \n" . ' @endslot',
            str_replace("\r\n", "\n", trim($result))
        );
    }

    public function testSlotsWithStyleDirectiveCanBeCompiled()
    {
        $this->mockViewFactory();
        $result = $this->compiler()->compileSlots('<x-slot name="foo" @style($styles)>
</x-slot>');

        $this->assertSame(
            "@slot('foo', null, ['style' => \\Hypervel\\View\\Compilers\\BladeCompiler::sanitizeComponentAttribute(\\Hypervel\\Support\\Arr::toCssStyles(\$styles))]) \n" . ' @endslot',
            str_replace("\r\n", "\n", trim($result))
        );
    }

    public function testBasicComponentParsing()
    {
        $this->mockViewFactory();

        $result = $this->compiler(['alert' => TestAlertComponent::class])->compileTags('<div><x-alert type="foo" limit="5" @click="foo" wire:click="changePlan(\'{{ $plan }}\')" required x-intersect.margin.-50%.0px="visibleSection = \'profile\'" /><x-alert /></div>');

        $this->assertSame("<div>##BEGIN-COMPONENT-CLASS##@component('Hypervel\\Tests\\View\\Blade\\TestAlertComponent', 'alert', [])
<?php if (isset(\$attributes) && \$attributes instanceof Hypervel\\View\\ComponentAttributeBag): ?>
<?php \$attributes = \$attributes->except(\\Hypervel\\Tests\\View\\Blade\\TestAlertComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php \$component->withAttributes(['type' => 'foo','limit' => '5','@click' => 'foo','wire:click' => 'changePlan(\\''.e(\$plan).'\\')','required' => true,'x-intersect.margin.-50%.0px' => 'visibleSection = \\'profile\\'']); ?>\n"
. "@endComponentClass##END-COMPONENT-CLASS####BEGIN-COMPONENT-CLASS##@component('Hypervel\\Tests\\View\\Blade\\TestAlertComponent', 'alert', [])
<?php if (isset(\$attributes) && \$attributes instanceof Hypervel\\View\\ComponentAttributeBag): ?>
<?php \$attributes = \$attributes->except(\\Hypervel\\Tests\\View\\Blade\\TestAlertComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php \$component->withAttributes([]); ?>\n"
. '@endComponentClass##END-COMPONENT-CLASS##</div>', trim($result));
    }

    public function testNestedDefaultComponentParsing(): void
    {
        $container = new Container;
        $app = m::mock(ApplicationContract::class);
        $container->instance(ApplicationContract::class, $app);
        $factory = m::mock(Factory::class);
        $container->instance(Factory::class, $factory);
        $app->expects('getNamespace')->andReturn('App\\');
        Container::setInstance($container);

        $result = $this->compiler()->compileTags('<div><x-card /></div>');

        $this->assertSame("<div>##BEGIN-COMPONENT-CLASS##@component('App\\View\\Components\\Card\\Card', 'card', [])
<?php if (isset(\$attributes) && \$attributes instanceof Hypervel\\View\\ComponentAttributeBag): ?>
<?php \$attributes = \$attributes->except(\\App\\View\\Components\\Card\\Card::ignoredParameterNames()); ?>
<?php endif; ?>
<?php \$component->withAttributes([]); ?>\n"
            . '@endComponentClass##END-COMPONENT-CLASS##</div>', trim($result));
    }

    public function testCustomNamespaceNestedDefaultComponentParsing(): void
    {
        $this->mockViewFactory();

        $result = $this->compiler(namespaces: ['nightshade' => 'Nightshade\View\Components'])
            ->compileTags('<div><x-nightshade::accordion /></div>');

        $this->assertSame("<div>##BEGIN-COMPONENT-CLASS##@component('Nightshade\\View\\Components\\Accordion\\Accordion', 'nightshade::accordion', [])
<?php if (isset(\$attributes) && \$attributes instanceof Hypervel\\View\\ComponentAttributeBag): ?>
<?php \$attributes = \$attributes->except(\\Nightshade\\View\\Components\\Accordion\\Accordion::ignoredParameterNames()); ?>
<?php endif; ?>
<?php \$component->withAttributes([]); ?>\n"
            . '@endComponentClass##END-COMPONENT-CLASS##</div>', trim($result));
    }

    public function testBasicComponentWithEmptyAttributesParsing()
    {
        $this->mockViewFactory();
        $result = $this->compiler(['alert' => TestAlertComponent::class])->compileTags('<div><x-alert type="" limit=\'\' @click="" required /></div>');

        $this->assertSame("<div>##BEGIN-COMPONENT-CLASS##@component('Hypervel\\Tests\\View\\Blade\\TestAlertComponent', 'alert', [])
<?php if (isset(\$attributes) && \$attributes instanceof Hypervel\\View\\ComponentAttributeBag): ?>
<?php \$attributes = \$attributes->except(\\Hypervel\\Tests\\View\\Blade\\TestAlertComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php \$component->withAttributes(['type' => '','limit' => '','@click' => '','required' => true]); ?>\n"
. '@endComponentClass##END-COMPONENT-CLASS##</div>', trim($result));
    }

    public function testDataCamelCasing()
    {
        $this->mockViewFactory();
        $result = $this->compiler(['profile' => TestProfileComponent::class])->compileTags('<x-profile user-id="1"></x-profile>');

        $this->assertSame("##BEGIN-COMPONENT-CLASS##@component('Hypervel\\Tests\\View\\Blade\\TestProfileComponent', 'profile', ['userId' => '1'])
<?php if (isset(\$attributes) && \$attributes instanceof Hypervel\\View\\ComponentAttributeBag): ?>
<?php \$attributes = \$attributes->except(\\Hypervel\\Tests\\View\\Blade\\TestProfileComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php \$component->withAttributes([]); ?> @endComponentClass##END-COMPONENT-CLASS##", trim($result));
    }

    public function testColonData()
    {
        $this->mockViewFactory();
        $result = $this->compiler(['profile' => TestProfileComponent::class])->compileTags('<x-profile :user-id="1"></x-profile>');

        $this->assertSame("##BEGIN-COMPONENT-CLASS##@component('Hypervel\\Tests\\View\\Blade\\TestProfileComponent', 'profile', ['userId' => 1])
<?php if (isset(\$attributes) && \$attributes instanceof Hypervel\\View\\ComponentAttributeBag): ?>
<?php \$attributes = \$attributes->except(\\Hypervel\\Tests\\View\\Blade\\TestProfileComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php \$component->withAttributes([]); ?> @endComponentClass##END-COMPONENT-CLASS##", trim($result));
    }

    public function testColonDataShortSyntax()
    {
        $this->mockViewFactory();
        $result = $this->compiler(['profile' => TestProfileComponent::class])->compileTags('<x-profile :$userId></x-profile>');

        $this->assertSame("##BEGIN-COMPONENT-CLASS##@component('Hypervel\\Tests\\View\\Blade\\TestProfileComponent', 'profile', ['userId' => \$userId])
<?php if (isset(\$attributes) && \$attributes instanceof Hypervel\\View\\ComponentAttributeBag): ?>
<?php \$attributes = \$attributes->except(\\Hypervel\\Tests\\View\\Blade\\TestProfileComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php \$component->withAttributes([]); ?> @endComponentClass##END-COMPONENT-CLASS##", trim($result));
    }

    public function testColonDataWithStaticClassProperty()
    {
        $this->mockViewFactory();
        $result = $this->compiler(['profile' => TestProfileComponent::class])->compileTags('<x-profile :userId="User::$id"></x-profile>');

        $this->assertSame("##BEGIN-COMPONENT-CLASS##@component('Hypervel\\Tests\\View\\Blade\\TestProfileComponent', 'profile', ['userId' => User::\$id])
<?php if (isset(\$attributes) && \$attributes instanceof Hypervel\\View\\ComponentAttributeBag): ?>
<?php \$attributes = \$attributes->except(\\Hypervel\\Tests\\View\\Blade\\TestProfileComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php \$component->withAttributes([]); ?> @endComponentClass##END-COMPONENT-CLASS##", trim($result));
    }

    public function testColonDataWithStaticClassPropertyAndMultipleAttributes()
    {
        $this->mockViewFactory();
        $result = $this->compiler(['input' => TestInputComponent::class])->compileTags('<x-input :label="Input::$label" :$name value="Joe"></x-input>');

        $this->assertSame("##BEGIN-COMPONENT-CLASS##@component('Hypervel\\Tests\\View\\Blade\\TestInputComponent', 'input', ['label' => Input::\$label,'name' => \$name,'value' => 'Joe'])
<?php if (isset(\$attributes) && \$attributes instanceof Hypervel\\View\\ComponentAttributeBag): ?>
<?php \$attributes = \$attributes->except(\\Hypervel\\Tests\\View\\Blade\\TestInputComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php \$component->withAttributes([]); ?> @endComponentClass##END-COMPONENT-CLASS##", trim($result));

        $result = $this->compiler(['input' => TestInputComponent::class])->compileTags('<x-input value="Joe" :$name :label="Input::$label"></x-input>');

        $this->assertSame("##BEGIN-COMPONENT-CLASS##@component('Hypervel\\Tests\\View\\Blade\\TestInputComponent', 'input', ['value' => 'Joe','name' => \$name,'label' => Input::\$label])
<?php if (isset(\$attributes) && \$attributes instanceof Hypervel\\View\\ComponentAttributeBag): ?>
<?php \$attributes = \$attributes->except(\\Hypervel\\Tests\\View\\Blade\\TestInputComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php \$component->withAttributes([]); ?> @endComponentClass##END-COMPONENT-CLASS##", trim($result));
    }

    public function testSelfClosingComponentWithColonDataShortSyntax()
    {
        $this->mockViewFactory();
        $result = $this->compiler(['profile' => TestProfileComponent::class])->compileTags('<x-profile :$userId/>');

        $this->assertSame("##BEGIN-COMPONENT-CLASS##@component('Hypervel\\Tests\\View\\Blade\\TestProfileComponent', 'profile', ['userId' => \$userId])
<?php if (isset(\$attributes) && \$attributes instanceof Hypervel\\View\\ComponentAttributeBag): ?>
<?php \$attributes = \$attributes->except(\\Hypervel\\Tests\\View\\Blade\\TestProfileComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php \$component->withAttributes([]); ?>\n"
. '@endComponentClass##END-COMPONENT-CLASS##', trim($result));
    }

    public function testSelfClosingComponentWithColonDataAndStaticClassPropertyShortSyntax()
    {
        $this->mockViewFactory();
        $result = $this->compiler(['profile' => TestProfileComponent::class])->compileTags('<x-profile :userId="User::$id"/>');

        $this->assertSame("##BEGIN-COMPONENT-CLASS##@component('Hypervel\\Tests\\View\\Blade\\TestProfileComponent', 'profile', ['userId' => User::\$id])
<?php if (isset(\$attributes) && \$attributes instanceof Hypervel\\View\\ComponentAttributeBag): ?>
<?php \$attributes = \$attributes->except(\\Hypervel\\Tests\\View\\Blade\\TestProfileComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php \$component->withAttributes([]); ?>\n"
. '@endComponentClass##END-COMPONENT-CLASS##', trim($result));
    }

    public function testSelfClosingComponentWithColonDataMultipleAttributesAndStaticClassPropertyShortSyntax()
    {
        $this->mockViewFactory();
        $result = $this->compiler(['input' => TestInputComponent::class])->compileTags('<x-input :label="Input::$label" value="Joe" :$name />');

        $this->assertSame("##BEGIN-COMPONENT-CLASS##@component('Hypervel\\Tests\\View\\Blade\\TestInputComponent', 'input', ['label' => Input::\$label,'value' => 'Joe','name' => \$name])
<?php if (isset(\$attributes) && \$attributes instanceof Hypervel\\View\\ComponentAttributeBag): ?>
<?php \$attributes = \$attributes->except(\\Hypervel\\Tests\\View\\Blade\\TestInputComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php \$component->withAttributes([]); ?>\n"
. '@endComponentClass##END-COMPONENT-CLASS##', trim($result));

        $result = $this->compiler(['input' => TestInputComponent::class])->compileTags('<x-input :$name :label="Input::$label" value="Joe" />');

        $this->assertSame("##BEGIN-COMPONENT-CLASS##@component('Hypervel\\Tests\\View\\Blade\\TestInputComponent', 'input', ['name' => \$name,'label' => Input::\$label,'value' => 'Joe'])
<?php if (isset(\$attributes) && \$attributes instanceof Hypervel\\View\\ComponentAttributeBag): ?>
<?php \$attributes = \$attributes->except(\\Hypervel\\Tests\\View\\Blade\\TestInputComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php \$component->withAttributes([]); ?>\n"
. '@endComponentClass##END-COMPONENT-CLASS##', trim($result));
    }

    public function testEscapedColonAttribute()
    {
        $this->mockViewFactory();
        $result = $this->compiler(['profile' => TestProfileComponent::class])->compileTags('<x-profile :user-id="1" ::title="user.name"></x-profile>');

        $this->assertSame("##BEGIN-COMPONENT-CLASS##@component('Hypervel\\Tests\\View\\Blade\\TestProfileComponent', 'profile', ['userId' => 1])
<?php if (isset(\$attributes) && \$attributes instanceof Hypervel\\View\\ComponentAttributeBag): ?>
<?php \$attributes = \$attributes->except(\\Hypervel\\Tests\\View\\Blade\\TestProfileComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php \$component->withAttributes([':title' => 'user.name']); ?> @endComponentClass##END-COMPONENT-CLASS##", trim($result));
    }

    public function testColonAttributesIsEscapedIfStrings()
    {
        $this->mockViewFactory();
        $result = $this->compiler(['profile' => TestProfileComponent::class])->compileTags('<x-profile :src="\'foo\'"></x-profile>');

        $this->assertSame("##BEGIN-COMPONENT-CLASS##@component('Hypervel\\Tests\\View\\Blade\\TestProfileComponent', 'profile', [])
<?php if (isset(\$attributes) && \$attributes instanceof Hypervel\\View\\ComponentAttributeBag): ?>
<?php \$attributes = \$attributes->except(\\Hypervel\\Tests\\View\\Blade\\TestProfileComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php \$component->withAttributes(['src' => \\Hypervel\\View\\Compilers\\BladeCompiler::sanitizeComponentAttribute('foo')]); ?> @endComponentClass##END-COMPONENT-CLASS##", trim($result));
    }

    public function testClassDirective()
    {
        $this->mockViewFactory();
        $result = $this->compiler(['profile' => TestProfileComponent::class])->compileTags('<x-profile @class(["bar"=>true])></x-profile>');

        $this->assertSame("##BEGIN-COMPONENT-CLASS##@component('Hypervel\\Tests\\View\\Blade\\TestProfileComponent', 'profile', [])
<?php if (isset(\$attributes) && \$attributes instanceof Hypervel\\View\\ComponentAttributeBag): ?>
<?php \$attributes = \$attributes->except(\\Hypervel\\Tests\\View\\Blade\\TestProfileComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php \$component->withAttributes(['class' => \\Hypervel\\View\\Compilers\\BladeCompiler::sanitizeComponentAttribute(\\Hypervel\\Support\\Arr::toCssClasses(['bar'=>true]))]); ?> @endComponentClass##END-COMPONENT-CLASS##", trim($result));
    }

    public function testStyleDirective()
    {
        $this->mockViewFactory();
        $result = $this->compiler(['profile' => TestProfileComponent::class])->compileTags('<x-profile @style(["bar"=>true])></x-profile>');

        $this->assertSame("##BEGIN-COMPONENT-CLASS##@component('Hypervel\\Tests\\View\\Blade\\TestProfileComponent', 'profile', [])
<?php if (isset(\$attributes) && \$attributes instanceof Hypervel\\View\\ComponentAttributeBag): ?>
<?php \$attributes = \$attributes->except(\\Hypervel\\Tests\\View\\Blade\\TestProfileComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php \$component->withAttributes(['style' => \\Hypervel\\View\\Compilers\\BladeCompiler::sanitizeComponentAttribute(\\Hypervel\\Support\\Arr::toCssStyles(['bar'=>true]))]); ?> @endComponentClass##END-COMPONENT-CLASS##", trim($result));
    }

    public function testColonNestedComponentParsing()
    {
        $this->mockViewFactory();
        $result = $this->compiler(['foo:alert' => TestAlertComponent::class])->compileTags('<x-foo:alert></x-foo:alert>');

        $this->assertSame("##BEGIN-COMPONENT-CLASS##@component('Hypervel\\Tests\\View\\Blade\\TestAlertComponent', 'foo:alert', [])
<?php if (isset(\$attributes) && \$attributes instanceof Hypervel\\View\\ComponentAttributeBag): ?>
<?php \$attributes = \$attributes->except(\\Hypervel\\Tests\\View\\Blade\\TestAlertComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php \$component->withAttributes([]); ?> @endComponentClass##END-COMPONENT-CLASS##", trim($result));
    }

    public function testColonStartingNestedComponentParsing()
    {
        $this->mockViewFactory();
        $result = $this->compiler(['foo:alert' => TestAlertComponent::class])->compileTags('<x:foo:alert></x-foo:alert>');

        $this->assertSame("##BEGIN-COMPONENT-CLASS##@component('Hypervel\\Tests\\View\\Blade\\TestAlertComponent', 'foo:alert', [])
<?php if (isset(\$attributes) && \$attributes instanceof Hypervel\\View\\ComponentAttributeBag): ?>
<?php \$attributes = \$attributes->except(\\Hypervel\\Tests\\View\\Blade\\TestAlertComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php \$component->withAttributes([]); ?> @endComponentClass##END-COMPONENT-CLASS##", trim($result));
    }

    public function testSelfClosingComponentsCanBeCompiled()
    {
        $this->mockViewFactory();
        $result = $this->compiler(['alert' => TestAlertComponent::class])->compileTags('<div><x-alert/></div>');

        $this->assertSame("<div>##BEGIN-COMPONENT-CLASS##@component('Hypervel\\Tests\\View\\Blade\\TestAlertComponent', 'alert', [])
<?php if (isset(\$attributes) && \$attributes instanceof Hypervel\\View\\ComponentAttributeBag): ?>
<?php \$attributes = \$attributes->except(\\Hypervel\\Tests\\View\\Blade\\TestAlertComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php \$component->withAttributes([]); ?>\n"
. '@endComponentClass##END-COMPONENT-CLASS##</div>', trim($result));
    }

    public function testClassesCanBeFoundByComponents(): void
    {
        $this->mockViewFactory();
        $compiler = $this->compiler(namespaces: ['nightshade' => 'Nightshade\View\Components']);

        $result = $compiler->findClassByComponent('nightshade::calendar');
        $this->assertSame('Nightshade\View\Components\Calendar', trim($result));

        $result = $compiler->findClassByComponent('nightshade::accordion');
        $this->assertSame('Nightshade\View\Components\Accordion\Accordion', trim($result));
    }

    public function testClassNamesCanBeGuessed(): void
    {
        $container = new Container;
        $app = m::mock(ApplicationContract::class);
        $app->expects('getNamespace')->andReturn('App\\');
        $container->instance(ApplicationContract::class, $app);
        Container::setInstance($container);

        $result = $this->compiler()->guessClassName('alert');

        $this->assertSame('App\View\Components\Alert', trim($result));
    }

    public function testClassNamesCanBeGuessedWithNamespaces(): void
    {
        $container = new Container;
        $app = m::mock(ApplicationContract::class);
        $container->instance(ApplicationContract::class, $app);
        $app->expects('getNamespace')->andReturn('App\\');
        Container::setInstance($container);

        $result = $this->compiler()->guessClassName('base.alert');

        $this->assertSame('App\View\Components\Base\Alert', trim($result));
    }

    public function testComponentsCanBeCompiledWithHyphenAttributes()
    {
        $this->mockViewFactory();

        $result = $this->compiler(['alert' => TestAlertComponent::class])->compileTags('<x-alert class="bar" wire:model="foo" x-on:click="bar" @click="baz" />');

        $this->assertSame("##BEGIN-COMPONENT-CLASS##@component('Hypervel\\Tests\\View\\Blade\\TestAlertComponent', 'alert', [])
<?php if (isset(\$attributes) && \$attributes instanceof Hypervel\\View\\ComponentAttributeBag): ?>
<?php \$attributes = \$attributes->except(\\Hypervel\\Tests\\View\\Blade\\TestAlertComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php \$component->withAttributes(['class' => 'bar','wire:model' => 'foo','x-on:click' => 'bar','@click' => 'baz']); ?>\n"
. '@endComponentClass##END-COMPONENT-CLASS##', trim($result));
    }

    public function testSelfClosingComponentsCanBeCompiledWithDataAndAttributes()
    {
        $this->mockViewFactory();
        $result = $this->compiler(['alert' => TestAlertComponent::class])->compileTags('<x-alert title="foo" class="bar" wire:model="foo" />');

        $this->assertSame("##BEGIN-COMPONENT-CLASS##@component('Hypervel\\Tests\\View\\Blade\\TestAlertComponent', 'alert', ['title' => 'foo'])
<?php if (isset(\$attributes) && \$attributes instanceof Hypervel\\View\\ComponentAttributeBag): ?>
<?php \$attributes = \$attributes->except(\\Hypervel\\Tests\\View\\Blade\\TestAlertComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php \$component->withAttributes(['class' => 'bar','wire:model' => 'foo']); ?>\n"
. '@endComponentClass##END-COMPONENT-CLASS##', trim($result));
    }

    public function testComponentCanReceiveAttributeBag()
    {
        $this->mockViewFactory();

        $result = $this->compiler(['profile' => TestProfileComponent::class])->compileTags('<x-profile class="bar" {{ $attributes }} wire:model="foo"></x-profile>');

        $this->assertSame("##BEGIN-COMPONENT-CLASS##@component('Hypervel\\Tests\\View\\Blade\\TestProfileComponent', 'profile', [])
<?php if (isset(\$attributes) && \$attributes instanceof Hypervel\\View\\ComponentAttributeBag): ?>
<?php \$attributes = \$attributes->except(\\Hypervel\\Tests\\View\\Blade\\TestProfileComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php \$component->withAttributes(['class' => 'bar','attributes' => \\Hypervel\\View\\Compilers\\BladeCompiler::sanitizeComponentAttribute(\$attributes),'wire:model' => 'foo']); ?> @endComponentClass##END-COMPONENT-CLASS##", trim($result));
    }

    public function testSelfClosingComponentCanReceiveAttributeBag()
    {
        $this->mockViewFactory();

        $result = $this->compiler(['alert' => TestAlertComponent::class])->compileTags('<div><x-alert title="foo" class="bar" {{ $attributes->merge([\'class\' => \'test\']) }} wire:model="foo" /></div>');

        $this->assertSame("<div>##BEGIN-COMPONENT-CLASS##@component('Hypervel\\Tests\\View\\Blade\\TestAlertComponent', 'alert', ['title' => 'foo'])
<?php if (isset(\$attributes) && \$attributes instanceof Hypervel\\View\\ComponentAttributeBag): ?>
<?php \$attributes = \$attributes->except(\\Hypervel\\Tests\\View\\Blade\\TestAlertComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php \$component->withAttributes(['class' => 'bar','attributes' => \\Hypervel\\View\\Compilers\\BladeCompiler::sanitizeComponentAttribute(\$attributes->merge(['class' => 'test'])),'wire:model' => 'foo']); ?>\n"
            . '@endComponentClass##END-COMPONENT-CLASS##</div>', trim($result));
    }

    public function testComponentsCanHaveAttachedWord()
    {
        $this->mockViewFactory();
        $result = $this->compiler(['profile' => TestProfileComponent::class])->compileTags('<x-profile></x-profile>Words');

        $this->assertSame("##BEGIN-COMPONENT-CLASS##@component('Hypervel\\Tests\\View\\Blade\\TestProfileComponent', 'profile', [])
<?php if (isset(\$attributes) && \$attributes instanceof Hypervel\\View\\ComponentAttributeBag): ?>
<?php \$attributes = \$attributes->except(\\Hypervel\\Tests\\View\\Blade\\TestProfileComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php \$component->withAttributes([]); ?> @endComponentClass##END-COMPONENT-CLASS##Words", trim($result));
    }

    public function testSelfClosingComponentsCanHaveAttachedWord()
    {
        $this->mockViewFactory();
        $result = $this->compiler(['alert' => TestAlertComponent::class])->compileTags('<x-alert/>Words');

        $this->assertSame("##BEGIN-COMPONENT-CLASS##@component('Hypervel\\Tests\\View\\Blade\\TestAlertComponent', 'alert', [])
<?php if (isset(\$attributes) && \$attributes instanceof Hypervel\\View\\ComponentAttributeBag): ?>
<?php \$attributes = \$attributes->except(\\Hypervel\\Tests\\View\\Blade\\TestAlertComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php \$component->withAttributes([]); ?>\n"
. '@endComponentClass##END-COMPONENT-CLASS##Words', trim($result));
    }

    public function testSelfClosingComponentsCanBeCompiledWithBoundData()
    {
        $this->mockViewFactory();
        $result = $this->compiler(['alert' => TestAlertComponent::class])->compileTags('<x-alert :title="$title" class="bar" />');

        $this->assertSame("##BEGIN-COMPONENT-CLASS##@component('Hypervel\\Tests\\View\\Blade\\TestAlertComponent', 'alert', ['title' => \$title])
<?php if (isset(\$attributes) && \$attributes instanceof Hypervel\\View\\ComponentAttributeBag): ?>
<?php \$attributes = \$attributes->except(\\Hypervel\\Tests\\View\\Blade\\TestAlertComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php \$component->withAttributes(['class' => 'bar']); ?>\n"
. '@endComponentClass##END-COMPONENT-CLASS##', trim($result));
    }

    public function testPairedComponentTags()
    {
        $this->mockViewFactory();
        $result = $this->compiler(['alert' => TestAlertComponent::class])->compileTags('<x-alert>
</x-alert>');

        $this->assertSame("##BEGIN-COMPONENT-CLASS##@component('Hypervel\\Tests\\View\\Blade\\TestAlertComponent', 'alert', [])
<?php if (isset(\$attributes) && \$attributes instanceof Hypervel\\View\\ComponentAttributeBag): ?>
<?php \$attributes = \$attributes->except(\\Hypervel\\Tests\\View\\Blade\\TestAlertComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php \$component->withAttributes([]); ?>
 @endComponentClass##END-COMPONENT-CLASS##", trim($result));
    }

    public function testClasslessComponents(): void
    {
        $container = new Container;
        $app = m::mock(ApplicationContract::class);
        $container->instance(ApplicationContract::class, $app);
        $factory = m::mock(Factory::class);
        $container->instance(Factory::class, $factory);
        $app->expects('getNamespace')->andReturn('App\\');
        $factory->expects('exists')->andReturn(true);
        Container::setInstance($container);

        $result = $this->compiler()->compileTags('<x-anonymous-component :name="\'Taylor\'" :age="31" wire:model="foo" />');

        $this->assertSame("##BEGIN-COMPONENT-CLASS##@component('Hypervel\\View\\AnonymousComponent', 'anonymous-component', ['view' => 'components.anonymous-component','data' => ['name' => 'Taylor','age' => 31,'wire:model' => 'foo']])
<?php if (isset(\$attributes) && \$attributes instanceof Hypervel\\View\\ComponentAttributeBag): ?>
<?php \$attributes = \$attributes->except(\\Hypervel\\View\\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php \$component->withAttributes(['name' => \\Hypervel\\View\\Compilers\\BladeCompiler::sanitizeComponentAttribute('Taylor'),'age' => 31,'wire:model' => 'foo']); ?>\n"
. '@endComponentClass##END-COMPONENT-CLASS##', trim($result));
    }

    public function testClasslessComponentsWithIndexView(): void
    {
        $container = new Container;
        $app = m::mock(ApplicationContract::class);
        $container->instance(ApplicationContract::class, $app);
        $factory = m::mock(Factory::class);
        $container->instance(Factory::class, $factory);
        $app->expects('getNamespace')->andReturn('App\\');
        $factory->expects('exists')->twice()->andReturn(false, true);
        Container::setInstance($container);

        $result = $this->compiler()->compileTags('<x-anonymous-component :name="\'Taylor\'" :age="31" wire:model="foo" />');

        $this->assertSame("##BEGIN-COMPONENT-CLASS##@component('Hypervel\\View\\AnonymousComponent', 'anonymous-component', ['view' => 'components.anonymous-component.index','data' => ['name' => 'Taylor','age' => 31,'wire:model' => 'foo']])
<?php if (isset(\$attributes) && \$attributes instanceof Hypervel\\View\\ComponentAttributeBag): ?>
<?php \$attributes = \$attributes->except(\\Hypervel\\View\\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php \$component->withAttributes(['name' => \\Hypervel\\View\\Compilers\\BladeCompiler::sanitizeComponentAttribute('Taylor'),'age' => 31,'wire:model' => 'foo']); ?>\n"
. '@endComponentClass##END-COMPONENT-CLASS##', trim($result));
    }

    public function testClasslessComponentsWithComponentView(): void
    {
        $container = new Container;
        $app = m::mock(ApplicationContract::class);
        $container->instance(ApplicationContract::class, $app);
        $factory = m::mock(Factory::class);
        $container->instance(Factory::class, $factory);
        $app->expects('getNamespace')->andReturn('App\\');
        $factory->expects('exists')->times(3)->andReturn(false, false, true);
        Container::setInstance($container);

        $result = $this->compiler()->compileTags('<x-anonymous-component :name="\'Taylor\'" :age="31" wire:model="foo" />');

        $this->assertSame("##BEGIN-COMPONENT-CLASS##@component('Hypervel\\View\\AnonymousComponent', 'anonymous-component', ['view' => 'components.anonymous-component.anonymous-component','data' => ['name' => 'Taylor','age' => 31,'wire:model' => 'foo']])
<?php if (isset(\$attributes) && \$attributes instanceof Hypervel\\View\\ComponentAttributeBag): ?>
<?php \$attributes = \$attributes->except(\\Hypervel\\View\\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php \$component->withAttributes(['name' => \\Hypervel\\View\\Compilers\\BladeCompiler::sanitizeComponentAttribute('Taylor'),'age' => 31,'wire:model' => 'foo']); ?>\n"
            . '@endComponentClass##END-COMPONENT-CLASS##', trim($result));
    }

    public function testPackagesClasslessComponents(): void
    {
        $container = new Container;
        $app = m::mock(ApplicationContract::class);
        $container->instance(ApplicationContract::class, $app);
        $factory = m::mock(Factory::class);
        $container->instance(Factory::class, $factory);
        $app->expects('getNamespace')->andReturn('App\\');
        $factory->expects('exists')->andReturn(true);
        Container::setInstance($container);

        $result = $this->compiler()->compileTags('<x-package::anonymous-component :name="\'Taylor\'" :age="31" wire:model="foo" />');

        $this->assertSame("##BEGIN-COMPONENT-CLASS##@component('Hypervel\\View\\AnonymousComponent', 'package::anonymous-component', ['view' => 'package::components.anonymous-component','data' => ['name' => 'Taylor','age' => 31,'wire:model' => 'foo']])
<?php if (isset(\$attributes) && \$attributes instanceof Hypervel\\View\\ComponentAttributeBag): ?>
<?php \$attributes = \$attributes->except(\\Hypervel\\View\\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php \$component->withAttributes(['name' => \\Hypervel\\View\\Compilers\\BladeCompiler::sanitizeComponentAttribute('Taylor'),'age' => 31,'wire:model' => 'foo']); ?>\n"
. '@endComponentClass##END-COMPONENT-CLASS##', trim($result));
    }

    public function testClasslessComponentsWithAnonymousComponentNamespace(): void
    {
        $container = new Container;
        $app = m::mock(ApplicationContract::class);
        $container->instance(ApplicationContract::class, $app);
        $factory = m::mock(Factory::class);
        $container->instance(Factory::class, $factory);
        $app->expects('getNamespace')->andReturn('App\\');
        $factory->expects('exists')->times(4)->andReturnUsing(function (string $arg): bool {
            // In our test, we'll do as if the 'public.frontend.anonymous-component'
            // view exists and not the others.
            return $arg === 'public.frontend.anonymous-component';
        });
        Container::setInstance($container);

        $blade = m::mock(BladeCompiler::class)->makePartial();

        $blade->expects('getAnonymousComponentNamespaces')->andReturn([
            'frontend' => 'public.frontend',
        ]);

        $compiler = $this->compiler([], [], $blade);

        $result = $compiler->compileTags('<x-frontend::anonymous-component :name="\'Taylor\'" :age="31" wire:model="foo" />');

        $this->assertSame("##BEGIN-COMPONENT-CLASS##@component('Hypervel\\View\\AnonymousComponent', 'frontend::anonymous-component', ['view' => 'public.frontend.anonymous-component','data' => ['name' => 'Taylor','age' => 31,'wire:model' => 'foo']])
<?php if (isset(\$attributes) && \$attributes instanceof Hypervel\\View\\ComponentAttributeBag): ?>
<?php \$attributes = \$attributes->except(\\Hypervel\\View\\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php \$component->withAttributes(['name' => \\Hypervel\\View\\Compilers\\BladeCompiler::sanitizeComponentAttribute('Taylor'),'age' => 31,'wire:model' => 'foo']); ?>\n"
            . '@endComponentClass##END-COMPONENT-CLASS##', trim($result));
    }

    public function testClasslessComponentsWithAnonymousComponentNamespaceWithIndexView(): void
    {
        $container = new Container;
        $app = m::mock(ApplicationContract::class);
        $container->instance(ApplicationContract::class, $app);
        $factory = m::mock(Factory::class);
        $container->instance(Factory::class, $factory);
        $app->expects('getNamespace')->andReturn('App\\');
        $factory->expects('exists')->times(5)->andReturnUsing(function (string $viewNameBeingCheckedForExistence): bool {
            // In our test, we'll do as if the 'admin.auth.components.anonymous-component.index'
            // view exists and not the others.
            return $viewNameBeingCheckedForExistence === 'admin.auth.components.anonymous-component.index';
        });
        Container::setInstance($container);

        $blade = m::mock(BladeCompiler::class)->makePartial();

        $blade->expects('getAnonymousComponentNamespaces')->andReturn([
            'admin.auth' => 'admin.auth.components',
        ]);

        $compiler = $this->compiler([], [], $blade);

        $result = $compiler->compileTags('<x-admin.auth::anonymous-component :name="\'Taylor\'" :age="31" wire:model="foo" />');

        $this->assertSame("##BEGIN-COMPONENT-CLASS##@component('Hypervel\\View\\AnonymousComponent', 'admin.auth::anonymous-component', ['view' => 'admin.auth.components.anonymous-component.index','data' => ['name' => 'Taylor','age' => 31,'wire:model' => 'foo']])
<?php if (isset(\$attributes) && \$attributes instanceof Hypervel\\View\\ComponentAttributeBag): ?>
<?php \$attributes = \$attributes->except(\\Hypervel\\View\\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php \$component->withAttributes(['name' => \\Hypervel\\View\\Compilers\\BladeCompiler::sanitizeComponentAttribute('Taylor'),'age' => 31,'wire:model' => 'foo']); ?>\n"
            . '@endComponentClass##END-COMPONENT-CLASS##', trim($result));
    }

    public function testClasslessComponentsWithAnonymousComponentNamespaceWithComponentView(): void
    {
        $container = new Container;
        $app = m::mock(ApplicationContract::class);
        $container->instance(ApplicationContract::class, $app);
        $factory = m::mock(Factory::class);
        $container->instance(Factory::class, $factory);
        $app->expects('getNamespace')->andReturn('App\\');
        $factory->expects('exists')->times(6)->andReturnUsing(function (string $viewNameBeingCheckedForExistence): bool {
            // In our test, we'll do as if the 'admin.auth.components.anonymous-component.anonymous-component'
            // view exists and not the others.
            return $viewNameBeingCheckedForExistence === 'admin.auth.components.anonymous-component.anonymous-component';
        });
        Container::setInstance($container);

        $blade = m::mock(BladeCompiler::class)->makePartial();

        $blade->expects('getAnonymousComponentNamespaces')->andReturn([
            'admin.auth' => 'admin.auth.components',
        ]);

        $compiler = $this->compiler([], [], $blade);

        $result = $compiler->compileTags('<x-admin.auth::anonymous-component :name="\'Taylor\'" :age="31" wire:model="foo" />');

        $this->assertSame("##BEGIN-COMPONENT-CLASS##@component('Hypervel\\View\\AnonymousComponent', 'admin.auth::anonymous-component', ['view' => 'admin.auth.components.anonymous-component.anonymous-component','data' => ['name' => 'Taylor','age' => 31,'wire:model' => 'foo']])
<?php if (isset(\$attributes) && \$attributes instanceof Hypervel\\View\\ComponentAttributeBag): ?>
<?php \$attributes = \$attributes->except(\\Hypervel\\View\\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php \$component->withAttributes(['name' => \\Hypervel\\View\\Compilers\\BladeCompiler::sanitizeComponentAttribute('Taylor'),'age' => 31,'wire:model' => 'foo']); ?>\n"
            . '@endComponentClass##END-COMPONENT-CLASS##', trim($result));
    }

    public function testClasslessComponentsWithAnonymousComponentPath(): void
    {
        $container = new Container;
        $app = m::mock(ApplicationContract::class);
        $container->instance(ApplicationContract::class, $app);
        $factory = m::mock(Factory::class);
        $container->instance(Factory::class, $factory);
        $app->expects('getNamespace')->andReturn('App\\');
        $factory->expects('exists')->times(5)->andReturnUsing(function (string $arg): bool {
            return $arg === hash('xxh128', 'test-directory') . '::panel.index';
        });
        Container::setInstance($container);

        $blade = m::mock(BladeCompiler::class)->makePartial();

        $blade->expects('getAnonymousComponentPaths')->andReturn([
            ['path' => 'test-directory', 'prefix' => null, 'prefixHash' => hash('xxh128', 'test-directory')],
        ]);

        $compiler = $this->compiler([], [], $blade);

        $result = $compiler->compileTags('<x-panel />');

        $this->assertSame("##BEGIN-COMPONENT-CLASS##@component('Hypervel\\View\\AnonymousComponent', 'panel', ['view' => '" . hash('xxh128', 'test-directory') . "::panel.index','data' => []])
<?php if (isset(\$attributes) && \$attributes instanceof Hypervel\\View\\ComponentAttributeBag): ?>
<?php \$attributes = \$attributes->except(\\Hypervel\\View\\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php \$component->withAttributes([]); ?>\n"
            . '@endComponentClass##END-COMPONENT-CLASS##', trim($result));
    }

    public function testAnonymousComponentPathGeneratesXxh128PrefixHash(): void
    {
        $expectedHash = hash('xxh128', 'test-directory');
        $factory = m::mock(Factory::class);
        $factory->expects('addNamespace')->with($expectedHash, 'test-directory')->andReturnSelf();

        $container = new TestBladeApplication('base_path');
        $container->instance(Factory::class, $factory);
        $container->alias(Factory::class, 'view');

        Container::setInstance($container);

        $this->compiler->anonymousComponentPath('test-directory');

        $this->assertSame([
            [
                'path' => 'test-directory',
                'prefix' => null,
                'prefixHash' => $expectedHash,
            ],
        ], $this->compiler->getAnonymousComponentPaths());
    }

    public function testClasslessComponentsWithAnonymousComponentPathComponentName(): void
    {
        $container = new Container;
        $app = m::mock(ApplicationContract::class);
        $container->instance(ApplicationContract::class, $app);
        $factory = m::mock(Factory::class);
        $container->instance(Factory::class, $factory);
        $app->expects('getNamespace')->andReturn('App\\');
        $factory->expects('exists')->times(6)->andReturnUsing(function (string $arg): bool {
            return $arg === hash('xxh128', 'test-directory') . '::panel.panel';
        });
        Container::setInstance($container);

        $blade = m::mock(BladeCompiler::class)->makePartial();

        $blade->expects('getAnonymousComponentPaths')->andReturn([
            ['path' => 'test-directory', 'prefix' => null, 'prefixHash' => hash('xxh128', 'test-directory')],
        ]);

        $compiler = $this->compiler([], [], $blade);

        $result = $compiler->compileTags('<x-panel />');

        $this->assertSame("##BEGIN-COMPONENT-CLASS##@component('Hypervel\\View\\AnonymousComponent', 'panel', ['view' => '" . hash('xxh128', 'test-directory') . "::panel.panel','data' => []])
<?php if (isset(\$attributes) && \$attributes instanceof Hypervel\\View\\ComponentAttributeBag): ?>
<?php \$attributes = \$attributes->except(\\Hypervel\\View\\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php \$component->withAttributes([]); ?>\n"
            . '@endComponentClass##END-COMPONENT-CLASS##', trim($result));
    }

    public function testClasslessIndexComponentsWithAnonymousComponentPath(): void
    {
        $container = new Container;
        $app = m::mock(ApplicationContract::class);
        $container->instance(ApplicationContract::class, $app);
        $factory = m::mock(Factory::class);
        $container->instance(Factory::class, $factory);
        $app->expects('getNamespace')->andReturn('App\\');
        $factory->expects('exists')->times(4)->andReturnUsing(function (string $arg): bool {
            return $arg === hash('xxh128', 'test-directory') . '::panel';
        });
        Container::setInstance($container);

        $blade = m::mock(BladeCompiler::class)->makePartial();

        $blade->expects('getAnonymousComponentPaths')->andReturn([
            ['path' => 'test-directory', 'prefix' => null, 'prefixHash' => hash('xxh128', 'test-directory')],
        ]);

        $compiler = $this->compiler([], [], $blade);

        $result = $compiler->compileTags('<x-panel />');

        $this->assertSame("##BEGIN-COMPONENT-CLASS##@component('Hypervel\\View\\AnonymousComponent', 'panel', ['view' => '" . hash('xxh128', 'test-directory') . "::panel','data' => []])
<?php if (isset(\$attributes) && \$attributes instanceof Hypervel\\View\\ComponentAttributeBag): ?>
<?php \$attributes = \$attributes->except(\\Hypervel\\View\\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php \$component->withAttributes([]); ?>\n"
            . '@endComponentClass##END-COMPONENT-CLASS##', trim($result));
    }

    public function testAttributeSanitization(): void
    {
        $this->mockViewFactory();
        $class = new class implements Stringable {
            public function __toString(): string
            {
                return '<hi>';
            }
        };

        $model = new class extends Model {
            public static function getEventDispatcher(): ?Dispatcher
            {
                return null;
            }
        };

        $paginator = new Paginator([], 15);

        $this->assertEquals(e('<hi>'), BladeCompiler::sanitizeComponentAttribute('<hi>'));
        $this->assertEquals(e('1'), BladeCompiler::sanitizeComponentAttribute('1'));
        $this->assertEquals(1, BladeCompiler::sanitizeComponentAttribute(1));
        $this->assertEquals(e('<hi>'), BladeCompiler::sanitizeComponentAttribute($class));
        $this->assertSame($model, BladeCompiler::sanitizeComponentAttribute($model));
        $this->assertSame($paginator, BladeCompiler::sanitizeComponentAttribute($paginator));
    }

    public function testItThrowsAnExceptionForNonExistingAliases()
    {
        $this->mockViewFactory(false);

        $this->expectException(InvalidArgumentException::class);

        $this->compiler(['alert' => 'foo.bar'])->compileTags('<x-alert />');
    }

    public function testItThrowsAnExceptionForNonExistingClass(): void
    {
        $container = new Container;
        $app = m::mock(ApplicationContract::class);
        $container->instance(ApplicationContract::class, $app);
        $factory = m::mock(Factory::class);
        $container->instance(Factory::class, $factory);
        $app->expects('getNamespace')->andReturn('App\\');
        $factory->expects('exists')->times(3)->andReturn(false);
        Container::setInstance($container);

        $this->expectException(InvalidArgumentException::class);

        $this->compiler()->compileTags('<x-alert />');
    }

    public function testAttributesTreatedAsPropsAreRemovedFromFinalAttributes(): void
    {
        $factory = m::mock(Factory::class);
        $factory->shouldReceive('exists')->never();

        $container = new Container;
        $app = m::mock(ApplicationContract::class);
        $container->instance(ApplicationContract::class, $app);
        $app->shouldNotReceive('getNamespace');
        $container->instance(Factory::class, $factory);
        $container->alias(Factory::class, 'view');

        Container::setInstance($container);

        $attributes = new ComponentAttributeBag(['userId' => 'bar', 'other' => 'ok']);

        $component = m::mock(TestProfileComponent::class);
        $component->expects('withName')->with('profile')->andReturnSelf();
        $component->expects('shouldRender')->andReturn(true);
        $component->expects('resolveView')->andReturn('');
        $component->expects('data')->andReturn([]);
        $component->expects('withAttributes')->with(['attributes' => new ComponentAttributeBag(['other' => 'ok'])])->andReturnSelf();

        Component::resolveComponentsUsing(fn () => $component);

        $__env = m::mock(ViewFactory::class);
        $__env->expects('startComponent');
        $__env->expects('renderComponent');

        $template = $this->compiler(['profile' => TestProfileComponent::class])->compileTags('<x-profile {{ $attributes }} />');
        $template = $this->compiler->compileString($template);

        ob_start();
        eval(" ?> {$template} <?php ");
        ob_get_clean();

        $this->assertSame('bar', $attributes->get('userId'));
        $this->assertSame('ok', $attributes->get('other'));
    }

    public function testOriginalAttributesAreRestoredAfterRenderingChildComponentWithProps(): void
    {
        $factory = m::mock(Factory::class);
        $factory->shouldReceive('exists')->never();

        $container = new Container;
        $app = m::mock(ApplicationContract::class);
        $container->instance(ApplicationContract::class, $app);
        $app->shouldNotReceive('getNamespace');
        $container->instance(Factory::class, $factory);
        $container->alias(Factory::class, 'view');

        Container::setInstance($container);

        $attributes = new ComponentAttributeBag(['userId' => 'bar', 'other' => 'ok']);

        $containerComponent = m::mock(TestContainerComponent::class);
        $containerComponent->expects('withName')->with('container')->andReturnSelf();
        $containerComponent->expects('shouldRender')->andReturn(true);
        $containerComponent->expects('resolveView')->andReturn('');
        $containerComponent->expects('data')->andReturn([]);
        $containerComponent->expects('withAttributes')->andReturnSelf();

        $profileComponent = m::mock(TestProfileComponent::class);
        $profileComponent->expects('withName')->with('profile')->andReturnSelf();
        $profileComponent->expects('shouldRender')->andReturn(true);
        $profileComponent->expects('resolveView')->andReturn('');
        $profileComponent->expects('data')->andReturn([]);
        $profileComponent->expects('withAttributes')->with(['attributes' => new ComponentAttributeBag(['other' => 'ok'])])->andReturnSelf();

        Component::resolveComponentsUsing(fn ($component) => match ($component) {
            TestContainerComponent::class => $containerComponent,
            TestProfileComponent::class => $profileComponent,
        });

        $__env = m::mock(ViewFactory::class);
        $__env->expects('startComponent')->twice();
        $__env->expects('renderComponent')->twice();

        $template = $this->compiler([
            'container' => TestContainerComponent::class,
            'profile' => TestProfileComponent::class,
        ])->compileTags('<x-container><x-profile {{ $attributes }} /></x-container>');
        $template = $this->compiler->compileString($template);

        ob_start();
        eval(" ?> {$template} <?php ");
        ob_get_clean();

        $this->assertSame('bar', $attributes->get('userId'));
        $this->assertSame('ok', $attributes->get('other'));
    }

    /**
     * Register a view factory with a default existence result.
     */
    protected function mockViewFactory(bool $existsSucceeds = true): void
    {
        $container = new Container;
        $factory = m::mock(Factory::class);
        $container->instance(Factory::class, $factory);
        $container->alias(Factory::class, 'view');
        $factory->shouldReceive('exists')->andReturn($existsSucceeds);

        Container::setInstance($container);
    }

    /**
     * Create a component tag compiler.
     */
    protected function compiler(array $aliases = [], array $namespaces = [], ?BladeCompiler $blade = null): ComponentTagCompiler
    {
        return new ComponentTagCompiler(
            $aliases,
            $namespaces,
            $blade
        );
    }
}

class TestAlertComponent extends Component
{
    public $title;

    public function __construct($title = 'foo', $userId = 1)
    {
        $this->title = $title;
    }

    public function render(): View|Htmlable|Closure|string
    {
        return 'alert';
    }
}

class TestProfileComponent extends Component
{
    public $userId;

    public function __construct($userId = 'foo')
    {
        $this->userId = $userId;
    }

    public function render(): View|Htmlable|Closure|string
    {
        return 'profile';
    }
}

class TestInputComponent extends Component
{
    public $userId;

    public function __construct(
        protected $name,
        protected $label,
        protected $value,
    ) {
    }

    public function render(): View|Htmlable|Closure|string
    {
        return 'input';
    }
}

class TestContainerComponent extends Component
{
    public function render(): View|Htmlable|Closure|string
    {
        return 'container';
    }
}

class TestBladeApplication extends Application
{
    public function getNamespace(): string
    {
        return 'App\\';
    }
}

namespace App\View\Components\Card;

use Closure;
use Hypervel\Contracts\Support\Htmlable;
use Hypervel\Contracts\View\View;
use Hypervel\View\Component;

class Card extends Component
{
    public function render(): View|Htmlable|Closure|string
    {
        return 'card';
    }
}

namespace Nightshade\View\Components;

use Closure;
use Hypervel\Contracts\Support\Htmlable;
use Hypervel\Contracts\View\View;
use Hypervel\View\Component;

class Calendar extends Component
{
    /**
     * Get the view that represents the component.
     */
    public function render(): View|Htmlable|Closure|string
    {
        return 'calendar';
    }
}

namespace Nightshade\View\Components\Accordion;

use Closure;
use Hypervel\Contracts\Support\Htmlable;
use Hypervel\Contracts\View\View;
use Hypervel\View\Component;

class Accordion extends Component
{
    public function render(): View|Htmlable|Closure|string
    {
        return 'accordion';
    }
}
