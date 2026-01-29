<?php

declare(strict_types=1);

namespace Hypervel\Tests\View\Blade;

use Closure;
use Hypervel\Container\DefinitionSource;
use Hypervel\Context\ApplicationContext;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Application;
use Hypervel\Support\Contracts\Htmlable;
use Hypervel\View\Compilers\BladeCompiler;
use Hypervel\View\Compilers\ComponentTagCompiler;
use Hypervel\View\Component;
use Hypervel\View\ComponentAttributeBag;
use Hypervel\View\Contracts\Factory;
use Hypervel\View\Contracts\View;
use InvalidArgumentException;
use Mockery as m;
use Psr\EventDispatcher\EventDispatcherInterface;
use Stringable;

/**
 * @internal
 * @coversNothing
 */
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

    public function testNestedDefaultComponentParsing()
    {
        $this->mockViewFactory();

        $result = $this->compiler()->compileTags('<div><x-card /></div>');

        $this->assertSame("<div>##BEGIN-COMPONENT-CLASS##@component('App\\View\\Components\\Card\\Card', 'card', [])
<?php if (isset(\$attributes) && \$attributes instanceof Hypervel\\View\\ComponentAttributeBag): ?>
<?php \$attributes = \$attributes->except(\\App\\View\\Components\\Card\\Card::ignoredParameterNames()); ?>
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

    public function testClassNamesCanBeGuessed()
    {
        $this->mockViewFactory();

        $result = $this->compiler()->guessClassName('alert');

        $this->assertSame('App\View\Components\Alert', trim($result));
    }

    public function testClassNamesCanBeGuessedWithNamespaces()
    {
        $this->mockViewFactory();

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

    public function testClasslessComponents()
    {
        $this->mockViewFactory();

        $result = $this->compiler()->compileTags('<x-anonymous-component :name="\'Taylor\'" :age="31" wire:model="foo" />');

        $this->assertSame("##BEGIN-COMPONENT-CLASS##@component('Hypervel\\View\\AnonymousComponent', 'anonymous-component', ['view' => 'components.anonymous-component','data' => ['name' => 'Taylor','age' => 31,'wire:model' => 'foo']])
<?php if (isset(\$attributes) && \$attributes instanceof Hypervel\\View\\ComponentAttributeBag): ?>
<?php \$attributes = \$attributes->except(\\Hypervel\\View\\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php \$component->withAttributes(['name' => \\Hypervel\\View\\Compilers\\BladeCompiler::sanitizeComponentAttribute('Taylor'),'age' => 31,'wire:model' => 'foo']); ?>\n"
. '@endComponentClass##END-COMPONENT-CLASS##', trim($result));
    }

    public function testClasslessComponentsWithIndexView()
    {
        $this->mockViewFactory(false, true);

        $result = $this->compiler()->compileTags('<x-anonymous-component :name="\'Taylor\'" :age="31" wire:model="foo" />');

        $this->assertSame("##BEGIN-COMPONENT-CLASS##@component('Hypervel\\View\\AnonymousComponent', 'anonymous-component', ['view' => 'components.anonymous-component.index','data' => ['name' => 'Taylor','age' => 31,'wire:model' => 'foo']])
<?php if (isset(\$attributes) && \$attributes instanceof Hypervel\\View\\ComponentAttributeBag): ?>
<?php \$attributes = \$attributes->except(\\Hypervel\\View\\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php \$component->withAttributes(['name' => \\Hypervel\\View\\Compilers\\BladeCompiler::sanitizeComponentAttribute('Taylor'),'age' => 31,'wire:model' => 'foo']); ?>\n"
. '@endComponentClass##END-COMPONENT-CLASS##', trim($result));
    }

    public function testClasslessComponentsWithComponentView()
    {
        $this->mockViewFactory(false, false, true);

        $result = $this->compiler()->compileTags('<x-anonymous-component :name="\'Taylor\'" :age="31" wire:model="foo" />');

        $this->assertSame("##BEGIN-COMPONENT-CLASS##@component('Hypervel\\View\\AnonymousComponent', 'anonymous-component', ['view' => 'components.anonymous-component.anonymous-component','data' => ['name' => 'Taylor','age' => 31,'wire:model' => 'foo']])
<?php if (isset(\$attributes) && \$attributes instanceof Hypervel\\View\\ComponentAttributeBag): ?>
<?php \$attributes = \$attributes->except(\\Hypervel\\View\\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php \$component->withAttributes(['name' => \\Hypervel\\View\\Compilers\\BladeCompiler::sanitizeComponentAttribute('Taylor'),'age' => 31,'wire:model' => 'foo']); ?>\n"
            . '@endComponentClass##END-COMPONENT-CLASS##', trim($result));
    }

    public function testPackagesClasslessComponents()
    {
        $this->mockViewFactory();

        $result = $this->compiler()->compileTags('<x-package::anonymous-component :name="\'Taylor\'" :age="31" wire:model="foo" />');

        $this->assertSame("##BEGIN-COMPONENT-CLASS##@component('Hypervel\\View\\AnonymousComponent', 'package::anonymous-component', ['view' => 'package::components.anonymous-component','data' => ['name' => 'Taylor','age' => 31,'wire:model' => 'foo']])
<?php if (isset(\$attributes) && \$attributes instanceof Hypervel\\View\\ComponentAttributeBag): ?>
<?php \$attributes = \$attributes->except(\\Hypervel\\View\\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php \$component->withAttributes(['name' => \\Hypervel\\View\\Compilers\\BladeCompiler::sanitizeComponentAttribute('Taylor'),'age' => 31,'wire:model' => 'foo']); ?>\n"
. '@endComponentClass##END-COMPONENT-CLASS##', trim($result));
    }

    public function testClasslessComponentsWithAnonymousComponentNamespace()
    {
        $this->mockViewFactory(function ($arg) {
            // In our test, we'll do as if the 'public.frontend.anonymous-component'
            // view exists and not the others.
            return $arg === 'public.frontend.anonymous-component';
        });

        $blade = m::mock(BladeCompiler::class)->makePartial();

        $blade->shouldReceive('getAnonymousComponentNamespaces')->once()->andReturn([
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

    public function testClasslessComponentsWithAnonymousComponentNamespaceWithIndexView()
    {
        $this->mockViewFactory(function (string $viewNameBeingCheckedForExistence) {
            // In our test, we'll do as if the 'public.frontend.anonymous-component'
            // view exists and not the others.
            return $viewNameBeingCheckedForExistence === 'admin.auth.components.anonymous-component.index';
        });

        $blade = m::mock(BladeCompiler::class)->makePartial();

        $blade->shouldReceive('getAnonymousComponentNamespaces')->once()->andReturn([
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

    public function testClasslessComponentsWithAnonymousComponentNamespaceWithComponentView()
    {
        $this->mockViewFactory(function (string $viewNameBeingCheckedForExistence) {
            // In our test, we'll do as if the 'public.frontend.anonymous-component'
            // view exists and not the others.
            return $viewNameBeingCheckedForExistence === 'admin.auth.components.anonymous-component.anonymous-component';
        });

        $blade = m::mock(BladeCompiler::class)->makePartial();

        $blade->shouldReceive('getAnonymousComponentNamespaces')->once()->andReturn([
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

    public function testClasslessComponentsWithAnonymousComponentPath()
    {
        $this->mockViewFactory(function ($arg) {
            return $arg === md5('test-directory') . '::panel.index';
        });

        $blade = m::mock(BladeCompiler::class)->makePartial();

        $blade->shouldReceive('getAnonymousComponentPaths')->once()->andReturn([
            ['path' => 'test-directory', 'prefix' => null, 'prefixHash' => md5('test-directory')],
        ]);

        $compiler = $this->compiler([], [], $blade);

        $result = $compiler->compileTags('<x-panel />');

        $this->assertSame("##BEGIN-COMPONENT-CLASS##@component('Hypervel\\View\\AnonymousComponent', 'panel', ['view' => '" . md5('test-directory') . "::panel.index','data' => []])
<?php if (isset(\$attributes) && \$attributes instanceof Hypervel\\View\\ComponentAttributeBag): ?>
<?php \$attributes = \$attributes->except(\\Hypervel\\View\\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php \$component->withAttributes([]); ?>\n"
            . '@endComponentClass##END-COMPONENT-CLASS##', trim($result));
    }

    public function testClasslessComponentsWithAnonymousComponentPathComponentName()
    {
        $this->mockViewFactory(function ($arg) {
            return $arg === md5('test-directory') . '::panel.panel';
        });

        $blade = m::mock(BladeCompiler::class)->makePartial();

        $blade->shouldReceive('getAnonymousComponentPaths')->once()->andReturn([
            ['path' => 'test-directory', 'prefix' => null, 'prefixHash' => md5('test-directory')],
        ]);

        $compiler = $this->compiler([], [], $blade);

        $result = $compiler->compileTags('<x-panel />');

        $this->assertSame("##BEGIN-COMPONENT-CLASS##@component('Hypervel\\View\\AnonymousComponent', 'panel', ['view' => '" . md5('test-directory') . "::panel.panel','data' => []])
<?php if (isset(\$attributes) && \$attributes instanceof Hypervel\\View\\ComponentAttributeBag): ?>
<?php \$attributes = \$attributes->except(\\Hypervel\\View\\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php \$component->withAttributes([]); ?>\n"
            . '@endComponentClass##END-COMPONENT-CLASS##', trim($result));
    }

    public function testClasslessIndexComponentsWithAnonymousComponentPath()
    {
        $this->mockViewFactory(function ($arg) {
            return $arg === md5('test-directory') . '::panel';
        });

        $blade = m::mock(BladeCompiler::class)->makePartial();

        $blade->shouldReceive('getAnonymousComponentPaths')->once()->andReturn([
            ['path' => 'test-directory', 'prefix' => null, 'prefixHash' => md5('test-directory')],
        ]);

        $compiler = $this->compiler([], [], $blade);

        $result = $compiler->compileTags('<x-panel />');

        $this->assertSame("##BEGIN-COMPONENT-CLASS##@component('Hypervel\\View\\AnonymousComponent', 'panel', ['view' => '" . md5('test-directory') . "::panel','data' => []])
<?php if (isset(\$attributes) && \$attributes instanceof Hypervel\\View\\ComponentAttributeBag): ?>
<?php \$attributes = \$attributes->except(\\Hypervel\\View\\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php \$component->withAttributes([]); ?>\n"
            . '@endComponentClass##END-COMPONENT-CLASS##', trim($result));
    }

    public function testAttributeSanitization()
    {
        $this->mockViewFactory();
        $class = new class implements Stringable {
            public function __toString()
            {
                return '<hi>';
            }
        };

        $model = new class extends Model {
            public function getEventDispatcher(): ?EventDispatcherInterface
            {
                return null;
            }
        };

        $this->assertEquals(e('<hi>'), BladeCompiler::sanitizeComponentAttribute('<hi>'));
        $this->assertEquals(e('1'), BladeCompiler::sanitizeComponentAttribute('1'));
        $this->assertEquals(1, BladeCompiler::sanitizeComponentAttribute(1));
        $this->assertEquals(e('<hi>'), BladeCompiler::sanitizeComponentAttribute($class));
        $this->assertSame($model, BladeCompiler::sanitizeComponentAttribute($model));
    }

    public function testItThrowsAnExceptionForNonExistingAliases()
    {
        $this->mockViewFactory(false);

        $this->expectException(InvalidArgumentException::class);

        $this->compiler(['alert' => 'foo.bar'])->compileTags('<x-alert />');
    }

    public function testItThrowsAnExceptionForNonExistingClass()
    {
        $this->mockViewFactory(false);

        $this->expectException(InvalidArgumentException::class);

        $this->compiler()->compileTags('<x-alert />');
    }

    public function testAttributesTreatedAsPropsAreRemovedFromFinalAttributes()
    {
        $factory = m::mock(Factory::class);
        $factory->shouldReceive('exists')->never();

        $container = $this->getMockBuilder(Application::class)
            ->setConstructorArgs([
                new DefinitionSource([
                    Factory::class => fn () => $factory,
                ]),
                'bath_path',
            ])
            ->onlyMethods(['getNamespace'])
            ->getMock();
        $container->method('getNamespace')->willReturn('App\\');
        $container->alias(Factory::class, 'view');

        ApplicationContext::setContainer($container);

        $attributes = new ComponentAttributeBag(['userId' => 'bar', 'other' => 'ok']);

        $component = m::mock(TestProfileComponent::class);
        $component->shouldReceive('withName')->with('profile')->once();
        $component->shouldReceive('shouldRender')->once()->andReturn(true);
        $component->shouldReceive('resolveView')->once()->andReturn('');
        $component->shouldReceive('data')->once()->andReturn([]);
        $component->shouldReceive('withAttributes')->with(['attributes' => new ComponentAttributeBag(['other' => 'ok'])])->once();

        Component::resolveComponentsUsing(fn () => $component);

        $__env = m::mock(\Hypervel\View\Factory::class);
        $__env->shouldReceive('startComponent')->once();
        $__env->shouldReceive('renderComponent')->once();

        $template = $this->compiler(['profile' => TestProfileComponent::class])->compileTags('<x-profile {{ $attributes }} />');
        $template = $this->compiler->compileString($template);

        ob_start();
        eval(" ?> {$template} <?php ");
        ob_get_clean();

        $this->assertSame($attributes->get('userId'), 'bar');
        $this->assertSame($attributes->get('other'), 'ok');
    }

    public function testOriginalAttributesAreRestoredAfterRenderingChildComponentWithProps()
    {
        $factory = m::mock(Factory::class);
        $factory->shouldReceive('exists')->never();

        $container = $this->getMockBuilder(Application::class)
            ->setConstructorArgs([
                new DefinitionSource([
                    Factory::class => fn () => $factory,
                ]),
                'bath_path',
            ])
            ->onlyMethods(['getNamespace'])
            ->getMock();
        $container->method('getNamespace')->willReturn('App\\');
        $container->alias(Factory::class, 'view');

        ApplicationContext::setContainer($container);

        $attributes = new ComponentAttributeBag(['userId' => 'bar', 'other' => 'ok']);

        $containerComponent = m::mock(TestContainerComponent::class);
        $containerComponent->shouldReceive('withName')->with('container')->once();
        $containerComponent->shouldReceive('shouldRender')->once()->andReturn(true);
        $containerComponent->shouldReceive('resolveView')->once()->andReturn('');
        $containerComponent->shouldReceive('data')->once()->andReturn([]);
        $containerComponent->shouldReceive('withAttributes')->once();

        $profileComponent = m::mock(TestProfileComponent::class);
        $profileComponent->shouldReceive('withName')->with('profile')->once();
        $profileComponent->shouldReceive('shouldRender')->once()->andReturn(true);
        $profileComponent->shouldReceive('resolveView')->once()->andReturn('');
        $profileComponent->shouldReceive('data')->once()->andReturn([]);
        $profileComponent->shouldReceive('withAttributes')->with(['attributes' => new ComponentAttributeBag(['other' => 'ok'])])->once();

        Component::resolveComponentsUsing(fn ($component) => match ($component) {
            TestContainerComponent::class => $containerComponent,
            TestProfileComponent::class => $profileComponent,
        });

        $__env = m::mock(\Hypervel\View\Factory::class);
        $__env->shouldReceive('startComponent')->twice();
        $__env->shouldReceive('renderComponent')->twice();

        $template = $this->compiler([
            'container' => TestContainerComponent::class,
            'profile' => TestProfileComponent::class,
        ])->compileTags('<x-container><x-profile {{ $attributes }} /></x-container>');
        $template = $this->compiler->compileString($template);

        ob_start();
        eval(" ?> {$template} <?php ");
        ob_get_clean();

        $this->assertSame($attributes->get('userId'), 'bar');
        $this->assertSame($attributes->get('other'), 'ok');
    }

    protected function mockViewFactory(...$exists)
    {
        $exists = $exists ?: [true];
        $factory = m::mock(Factory::class);
        if ($exists[0] instanceof Closure) {
            $factory->shouldReceive('exists')->andReturnUsing($exists[0]);
        } else {
            $factory->shouldReceive('exists')->andReturn(...$exists);
        }

        $container = $this->getMockBuilder(Application::class)
            ->setConstructorArgs([
                new DefinitionSource([
                    Factory::class => fn () => $factory,
                ]),
                'bath_path',
            ])
            ->onlyMethods(['getNamespace'])
            ->getMock();
        $container->method('getNamespace')->willReturn('App\\');
        $container->alias(Factory::class, 'view');

        ApplicationContext::setContainer($container);
    }

    protected function compiler(array $aliases = [], array $namespaces = [], ?BladeCompiler $blade = null)
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

namespace App\View\Components\Card;

use Closure;
use Hypervel\Support\Contracts\Htmlable;
use Hypervel\View\Component;
use Hypervel\View\Contracts\View;

class Card extends Component
{
    public function render(): View|Htmlable|Closure|string
    {
        return 'card';
    }
}
