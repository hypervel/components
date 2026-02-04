<?php

declare(strict_types=1);

namespace Hypervel\Tests\Translation;

use Hypervel\Coroutine\Coroutine;
use Hypervel\Support\Carbon;
use Hypervel\Support\Collection;
use Hypervel\Translation\Contracts\Loader;
use Hypervel\Translation\MessageSelector;
use Hypervel\Translation\Translator;
use Mockery as m;
use PHPUnit\Framework\TestCase;

use function Hypervel\Coroutine\run;

enum TranslatorTestStringBackedEnum: string
{
    case February = 'February';
}

enum TranslatorTestIntBackedEnum: int
{
    case Thirteen = 13;
}

enum TranslatorTestUnitEnum
{
    case Hosni;
}

/**
 * @internal
 * @coversNothing
 */
class TranslatorTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
    }

    public function testHasMethodReturnsFalseWhenReturnedTranslationIsNull()
    {
        $translator = $this->getMockBuilder(Translator::class)->onlyMethods(['get'])->setConstructorArgs([$this->getLoader(), 'en'])->getMock();
        $translator->expects($this->once())->method('get')->with($this->equalTo('foo'), $this->equalTo([]), $this->equalTo('bar'))->willReturn('foo');
        $this->assertFalse($translator->has('foo', 'bar'));

        $translator = $this->getMockBuilder(Translator::class)->onlyMethods(['get'])->setConstructorArgs([$this->getLoader(), 'en', 'sp'])->getMock();
        $translator->expects($this->once())->method('get')->with($this->equalTo('foo'), $this->equalTo([]), $this->equalTo('bar'))->willReturn('bar');
        $this->assertTrue($translator->has('foo', 'bar'));

        $translator = $this->getMockBuilder(Translator::class)->onlyMethods(['get'])->setConstructorArgs([$this->getLoader(), 'en'])->getMock();
        $translator->expects($this->once())->method('get')->with($this->equalTo('foo'), $this->equalTo([]), $this->equalTo('bar'), false)->willReturn('bar');
        $this->assertTrue($translator->hasForLocale('foo', 'bar'));

        $translator = $this->getMockBuilder(Translator::class)->onlyMethods(['get'])->setConstructorArgs([$this->getLoader(), 'en'])->getMock();
        $translator->expects($this->once())->method('get')->with($this->equalTo('foo'), $this->equalTo([]), $this->equalTo('bar'), false)->willReturn('foo');
        $this->assertFalse($translator->hasForLocale('foo', 'bar'));

        $translator = new Translator($this->getLoader(), 'en');
        $translator->getLoader()->shouldReceive('load')->once()->with('en', '*', '*')->andReturn([]);
        $translator->getLoader()->shouldReceive('load')->once()->with('en', 'foo', '*')->andReturn(['foo' => 'bar']);
        $this->assertTrue($translator->hasForLocale('foo'));

        $translator = new Translator($this->getLoader(), 'en');
        $translator->getLoader()->shouldReceive('load')->once()->with('en', '*', '*')->andReturn([]);
        $translator->getLoader()->shouldReceive('load')->once()->with('en', 'foo', '*')->andReturn([]);
        $this->assertFalse($translator->hasForLocale('foo'));
    }

    public function testGetMethodProperlyLoadsAndRetrievesItem()
    {
        $translator = new Translator($this->getLoader(), 'en');
        $translator->getLoader()->shouldReceive('load')->once()->with('en', '*', '*')->andReturn([]);
        $translator->getLoader()->shouldReceive('load')->once()->with('en', 'bar', 'foo')->andReturn(['foo' => 'foo', 'baz' => 'breeze :foo', 'qux' => ['tree :foo', 'breeze :foo']]);
        $this->assertEquals(['tree bar', 'breeze bar'], $translator->get('foo::bar.qux', ['foo' => 'bar'], 'en'));
        $this->assertSame('breeze bar', $translator->get('foo::bar.baz', ['foo' => 'bar'], 'en'));
        $this->assertSame('foo', $translator->get('foo::bar.foo'));
    }

    public function testGetMethodProperlyLoadsAndRetrievesArrayItem()
    {
        $translator = new Translator($this->getLoader(), 'en');
        $translator->getLoader()->shouldReceive('load')->once()->with('en', '*', '*')->andReturn([]);
        $translator->getLoader()->shouldReceive('load')->once()->with('en', 'bar', 'foo')->andReturn(['foo' => 'foo', 'baz' => 'breeze :foo', 'qux' => ['tree :foo', 'breeze :foo', 'beep' => ['rock' => 'tree :foo']]]);
        $this->assertEquals(['foo' => 'foo', 'baz' => 'breeze bar', 'qux' => ['tree bar', 'breeze bar', 'beep' => ['rock' => 'tree bar']]], $translator->get('foo::bar', ['foo' => 'bar'], 'en'));
        $this->assertSame('breeze bar', $translator->get('foo::bar.baz', ['foo' => 'bar'], 'en'));
        $this->assertSame('foo', $translator->get('foo::bar.foo'));
    }

    public function testGetMethodForNonExistingReturnsSameKey()
    {
        $translator = new Translator($this->getLoader(), 'en');
        $translator->getLoader()->shouldReceive('load')->once()->with('en', '*', '*')->andReturn([]);
        $translator->getLoader()->shouldReceive('load')->once()->with('en', 'bar', 'foo')->andReturn(['foo' => 'foo', 'baz' => 'breeze :foo', 'qux' => ['tree :foo', 'breeze :foo']]);
        $translator->getLoader()->shouldReceive('load')->once()->with('en', 'unknown', 'foo')->andReturn([]);
        $this->assertSame('foo::unknown', $translator->get('foo::unknown', ['foo' => 'bar'], 'en'));
        $this->assertSame('foo::bar.unknown', $translator->get('foo::bar.unknown', ['foo' => 'bar'], 'en'));
        $this->assertSame('foo::unknown.bar', $translator->get('foo::unknown.bar'));
    }

    public function testTransMethodProperlyLoadsAndRetrievesItemWithHTMLInTheMessage()
    {
        $translator = new Translator($this->getLoader(), 'en');
        $translator->getLoader()->shouldReceive('load')->once()->with('en', '*', '*')->andReturn([]);
        $translator->getLoader()->shouldReceive('load')->once()->with('en', 'foo', '*')->andReturn(['bar' => 'breeze <p>test</p>']);
        $this->assertSame('breeze <p>test</p>', $translator->get('foo.bar', [], 'en'));
    }

    public function testGetMethodProperlyLoadsAndRetrievesItemWithCapitalization()
    {
        $translator = $this->getMockBuilder(Translator::class)->onlyMethods([])->setConstructorArgs([$this->getLoader(), 'en'])->getMock();
        $translator->getLoader()->shouldReceive('load')->once()->with('en', '*', '*')->andReturn([]);
        $translator->getLoader()->shouldReceive('load')->once()->with('en', 'bar', 'foo')->andReturn(['foo' => 'foo', 'baz' => 'breeze :0 :Foo :BAR']);
        $this->assertSame('breeze john Bar FOO', $translator->get('foo::bar.baz', ['john', 'foo' => 'bar', 'bar' => 'foo'], 'en'));
        $this->assertSame('foo', $translator->get('foo::bar.foo'));
    }

    public function testGetMethodProperlyLoadsAndRetrievesItemWithLongestReplacementsFirst()
    {
        $translator = new Translator($this->getLoader(), 'en');
        $translator->getLoader()->shouldReceive('load')->once()->with('en', '*', '*')->andReturn([]);
        $translator->getLoader()->shouldReceive('load')->once()->with('en', 'bar', 'foo')->andReturn(['foo' => 'foo', 'baz' => 'breeze :foo :foobar']);
        $this->assertSame('breeze bar taylor', $translator->get('foo::bar.baz', ['foo' => 'bar', 'foobar' => 'taylor'], 'en'));
        $this->assertSame('breeze foo bar baz taylor', $translator->get('foo::bar.baz', ['foo' => 'foo bar baz', 'foobar' => 'taylor'], 'en'));
        $this->assertSame('foo', $translator->get('foo::bar.foo'));
    }

    public function testGetMethodProperlyLoadsAndRetrievesItemForFallback()
    {
        $translator = new Translator($this->getLoader(), 'en');
        $translator->setFallback('lv');
        $translator->getLoader()->shouldReceive('load')->once()->with('en', '*', '*')->andReturn([]);
        $translator->getLoader()->shouldReceive('load')->once()->with('en', 'bar', 'foo')->andReturn([]);
        $translator->getLoader()->shouldReceive('load')->once()->with('lv', 'bar', 'foo')->andReturn(['foo' => 'foo', 'baz' => 'breeze :foo']);
        $this->assertSame('breeze bar', $translator->get('foo::bar.baz', ['foo' => 'bar'], 'en'));
        $this->assertSame('foo', $translator->get('foo::bar.foo'));
    }

    public function testGetMethodProperlyLoadsAndRetrievesItemForGlobalNamespace()
    {
        $translator = new Translator($this->getLoader(), 'en');
        $translator->getLoader()->shouldReceive('load')->once()->with('en', '*', '*')->andReturn([]);
        $translator->getLoader()->shouldReceive('load')->once()->with('en', 'foo', '*')->andReturn(['bar' => 'breeze :foo']);
        $this->assertSame('breeze bar', $translator->get('foo.bar', ['foo' => 'bar']));
    }

    public function testChoiceMethodProperlyLoadsAndRetrievesItemForAnInt()
    {
        $translator = $this->getMockBuilder(Translator::class)->onlyMethods(['get', 'localeForChoice'])->setConstructorArgs([$this->getLoader(), 'en'])->getMock();
        $translator->expects($this->once())->method('get')->with($this->equalTo('foo'), $this->equalTo([]), $this->equalTo('en'))->willReturn('line');
        $translator->expects($this->once())->method('localeForChoice')->with($this->equalTo('foo'), $this->equalTo(null))->willReturn('en');
        $translator->setSelector($selector = m::mock(MessageSelector::class));
        $selector->shouldReceive('choose')->once()->with('line', 10, 'en')->andReturn('choiced');

        $translator->choice('foo', 10, ['replace']);
    }

    public function testChoiceMethodProperlyLoadsAndRetrievesItemForAFloat()
    {
        $translator = $this->getMockBuilder(Translator::class)->onlyMethods(['get', 'localeForChoice'])->setConstructorArgs([$this->getLoader(), 'en'])->getMock();
        $translator->expects($this->once())->method('get')->with($this->equalTo('foo'), $this->equalTo([]), $this->equalTo('en'))->willReturn('line');
        $translator->expects($this->once())->method('localeForChoice')->with($this->equalTo('foo'), $this->equalTo(null))->willReturn('en');
        $translator->setSelector($selector = m::mock(MessageSelector::class));
        $selector->shouldReceive('choose')->once()->with('line', 1.2, 'en')->andReturn('choiced');

        $translator->choice('foo', 1.2, ['replace']);
    }

    public function testChoiceMethodProperlyCountsCollectionsAndLoadsAndRetrievesItem()
    {
        $translator = $this->getMockBuilder(Translator::class)->onlyMethods(['get', 'localeForChoice'])->setConstructorArgs([$this->getLoader(), 'en'])->getMock();
        $translator->expects($this->exactly(2))->method('get')->with($this->equalTo('foo'), $this->equalTo([]), $this->equalTo('en'))->willReturn('line');
        $translator->expects($this->exactly(2))->method('localeForChoice')->with($this->equalTo('foo'), $this->equalTo(null))->willReturn('en');
        $translator->setSelector($selector = m::mock(MessageSelector::class));
        $selector->shouldReceive('choose')->twice()->with('line', 3, 'en')->andReturn('choiced');

        $values = ['foo', 'bar', 'baz'];
        $translator->choice('foo', $values, ['replace']);

        $values = new Collection(['foo', 'bar', 'baz']);
        $translator->choice('foo', $values, ['replace']);
    }

    public function testChoiceMethodProperlySelectsLocaleForChoose()
    {
        $translator = $this->getMockBuilder(Translator::class)->onlyMethods(['get', 'hasForLocale'])->setConstructorArgs([$this->getLoader(), 'cs'])->getMock();
        $translator->setFallback('en');
        $translator->expects($this->once())->method('get')->with($this->equalTo('foo'), $this->equalTo([]), $this->equalTo('en'))->willReturn('line');
        $translator->expects($this->once())->method('hasForLocale')->with($this->equalTo('foo'), $this->equalTo('cs'))->willReturn(false);
        $translator->setSelector($selector = m::mock(MessageSelector::class));
        $selector->shouldReceive('choose')->once()->with('line', 10, 'en')->andReturn('choiced');

        $translator->choice('foo', 10, ['replace']);
    }

    public function testChoiceMethodProperlyUsesCustomCountReplacement()
    {
        $translator = $this->getMockBuilder(Translator::class)->onlyMethods(['get', 'localeForChoice'])->setConstructorArgs([$this->getLoader(), 'en'])->getMock();
        $translator->expects($this->once())->method('get')->with($this->equalTo(':count foos'), $this->equalTo([]), $this->equalTo('en'))->willReturn('{1} :count foos|[2,*] :count foos');
        $translator->expects($this->once())->method('localeForChoice')->with($this->equalTo(':count foos'), $this->equalTo(null))->willReturn('en');
        $translator->setSelector($selector = m::mock(MessageSelector::class));
        $selector->shouldReceive('choose')->once()->with('{1} :count foos|[2,*] :count foos', 1234, 'en')->andReturn(':count foos');

        $this->assertEquals('1,234 foos', $translator->choice(':count foos', 1234, ['count' => '1,234']));
    }

    public function testGetJson()
    {
        $translator = new Translator($this->getLoader(), 'en');
        $translator->getLoader()->shouldReceive('load')->once()->with('en', '*', '*')->andReturn(['foo' => 'one']);
        $this->assertSame('one', $translator->get('foo'));
    }

    public function testGetJsonReplaces()
    {
        $translator = new Translator($this->getLoader(), 'en');
        $translator->getLoader()->shouldReceive('load')->once()->with('en', '*', '*')->andReturn(['foo :i:c :u' => 'bar :i:c :u']);
        $this->assertSame('bar onetwo three', $translator->get('foo :i:c :u', ['i' => 'one', 'c' => 'two', 'u' => 'three']));
    }

    public function testGetJsonHasAtomicReplacements()
    {
        $translator = new Translator($this->getLoader(), 'en');
        $translator->getLoader()->shouldReceive('load')->once()->with('en', '*', '*')->andReturn(['Hello :foo!' => 'Hello :foo!']);
        $this->assertSame('Hello baz:bar!', $translator->get('Hello :foo!', ['foo' => 'baz:bar', 'bar' => 'abcdef']));
    }

    public function testGetJsonReplacesForAssociativeInput()
    {
        $translator = new Translator($this->getLoader(), 'en');
        $translator->getLoader()->shouldReceive('load')->once()->with('en', '*', '*')->andReturn(['foo :i :c' => 'bar :i :c']);
        $this->assertSame('bar eye see', $translator->get('foo :i :c', ['i' => 'eye', 'c' => 'see']));
    }

    public function testGetJsonPreservesOrder()
    {
        $translator = new Translator($this->getLoader(), 'en');
        $translator->getLoader()->shouldReceive('load')->once()->with('en', '*', '*')->andReturn(['to :name I give :greeting' => ':greeting :name']);
        $this->assertSame('Greetings David', $translator->get('to :name I give :greeting', ['name' => 'David', 'greeting' => 'Greetings']));
    }

    public function testGetJsonForNonExistingJsonKeyLooksForRegularKeys()
    {
        $translator = new Translator($this->getLoader(), 'en');
        $translator->getLoader()->shouldReceive('load')->once()->with('en', '*', '*')->andReturn([]);
        $translator->getLoader()->shouldReceive('load')->once()->with('en', 'foo', '*')->andReturn(['bar' => 'one']);
        $this->assertSame('one', $translator->get('foo.bar'));
    }

    public function testGetJsonForNonExistingJsonKeyLooksForRegularKeysAndReplace()
    {
        $translator = new Translator($this->getLoader(), 'en');
        $translator->getLoader()->shouldReceive('load')->once()->with('en', '*', '*')->andReturn([]);
        $translator->getLoader()->shouldReceive('load')->once()->with('en', 'foo', '*')->andReturn(['bar' => 'one :message']);
        $this->assertSame('one two', $translator->get('foo.bar', ['message' => 'two']));
    }

    public function testGetJsonForNonExistingReturnsSameKey()
    {
        $translator = new Translator($this->getLoader(), 'en');
        $translator->getLoader()->shouldReceive('load')->once()->with('en', '*', '*')->andReturn([]);
        $translator->getLoader()->shouldReceive('load')->once()->with('en', 'Foo that bar', '*')->andReturn([]);
        $this->assertSame('Foo that bar', $translator->get('Foo that bar'));
    }

    public function testGetJsonForNonExistingReturnsSameKeyAndReplaces()
    {
        $translator = new Translator($this->getLoader(), 'en');
        $translator->getLoader()->shouldReceive('load')->once()->with('en', '*', '*')->andReturn([]);
        $translator->getLoader()->shouldReceive('load')->once()->with('en', 'foo :message', '*')->andReturn([]);
        $this->assertSame('foo baz', $translator->get('foo :message', ['message' => 'baz']));
    }

    public function testEmptyFallbacks()
    {
        $translator = new Translator($this->getLoader(), 'en');
        $translator->getLoader()->shouldReceive('load')->once()->with('en', '*', '*')->andReturn([]);
        $translator->getLoader()->shouldReceive('load')->once()->with('en', 'foo :message', '*')->andReturn([]);
        $this->assertSame('foo ', $translator->get('foo :message', ['message' => null]));
    }

    public function testGetJsonReplacesWithStringable()
    {
        $translator = new Translator($this->getLoader(), 'en');
        $translator->getLoader()
            ->shouldReceive('load')
            ->once()
            ->with('en', '*', '*')
            ->andReturn(['test' => 'the date is :date']);

        $date = Carbon::createFromTimestamp(0);

        $this->assertSame(
            'the date is 1970-01-01 00:00:00',
            $translator->get('test', ['date' => $date])
        );

        $translator->stringable(function (Carbon $carbon) {
            return $carbon->format('jS M Y');
        });
        $this->assertSame(
            'the date is 1st Jan 1970',
            $translator->get('test', ['date' => $date])
        );
    }

    public function testGetJsonReplacesWithEnums()
    {
        $translator = new Translator($this->getLoader(), 'en');
        $translator->getLoader()
            ->shouldReceive('load')
            ->once()
            ->with('en', '*', '*')
            ->andReturn([
                'string_backed_enum' => 'Laravel 12 was released in :month 2025',
                'int_backed_enum' => 'Stay tuned for Laravel v:version',
                'unit_enum' => ':person gets excited about every new Laravel release',
            ]);

        $this->assertSame(
            'Laravel 12 was released in February 2025',
            $translator->get('string_backed_enum', ['month' => TranslatorTestStringBackedEnum::February])
        );

        $this->assertSame(
            'Stay tuned for Laravel v13',
            $translator->get('int_backed_enum', ['version' => TranslatorTestIntBackedEnum::Thirteen])
        );

        $this->assertSame(
            'Hosni gets excited about every new Laravel release',
            $translator->get('unit_enum', ['person' => TranslatorTestUnitEnum::Hosni])
        );
    }

    public function testTagReplacements()
    {
        $translator = new Translator($this->getLoader(), 'en');

        $translator->getLoader()->shouldReceive('load')->once()->with('en', '*', '*')->andReturn([]);
        $translator->getLoader()->shouldReceive('load')->once()->with('en', 'We have some nice <docs-link>documentation</docs-link>', '*')->andReturn([]);

        $this->assertSame(
            'We have some nice <a href="https://laravel.com/docs">documentation</a>',
            $translator->get(
                'We have some nice <docs-link>documentation</docs-link>',
                [
                    'docs-link' => fn ($children) => "<a href=\"https://laravel.com/docs\">{$children}</a>",
                ]
            )
        );
    }

    public function testTagReplacementsHandleMultipleOfSameTag()
    {
        $translator = new Translator($this->getLoader(), 'en');

        $translator->getLoader()->shouldReceive('load')->once()->with('en', '*', '*')->andReturn([]);
        $translator->getLoader()->shouldReceive('load')->once()->with('en', '<bold-this>bold</bold-this> something else <bold-this>also bold</bold-this>', '*')->andReturn([]);

        $this->assertSame(
            '<b>bold</b> something else <b>also bold</b>',
            $translator->get(
                '<bold-this>bold</bold-this> something else <bold-this>also bold</bold-this>',
                [
                    'bold-this' => fn ($children) => "<b>{$children}</b>",
                ]
            )
        );
    }

    public function testDetermineLocalesUsingMethod()
    {
        $translator = new Translator($this->getLoader(), 'en');
        $translator->determineLocalesUsing(function ($locales) {
            $this->assertSame(['en'], $locales);

            return ['en', 'lz'];
        });
        $translator->getLoader()->shouldReceive('load')->once()->with('en', '*', '*')->andReturn([]);
        $translator->getLoader()->shouldReceive('load')->once()->with('en', 'foo', '*')->andReturn([]);
        $translator->getLoader()->shouldReceive('load')->once()->with('lz', 'foo', '*')->andReturn([]);
        $this->assertSame('foo', $translator->get('foo'));
    }

    public function testSetLocale()
    {
        $translator = new Translator($this->getLoader(), 'en');

        run(function () use ($translator) {
            Coroutine::create(function () use ($translator) {
                $translator->setLocale('fr');
                $this->assertSame('fr', $translator->getLocale());
            });
        });

        $this->assertSame('en', $translator->getLocale());
    }

    protected function getLoader()
    {
        return m::mock(Loader::class);
    }
}
