<?php

declare(strict_types=1);

namespace Hypervel\Tests\Translation;

use Hypervel\Contracts\Translation\Loader;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Collection;
use Hypervel\Tests\TestCase;
use Hypervel\Tests\Translation\Fixtures\Enums\Bar;
use Hypervel\Tests\Translation\Fixtures\Enums\Baz;
use Hypervel\Tests\Translation\Fixtures\Enums\Foo;
use Hypervel\Translation\ArrayLoader;
use Hypervel\Translation\FileLoader;
use Hypervel\Translation\MissingTranslationGroups;
use Hypervel\Translation\Translator;
use InvalidArgumentException;
use Mockery as m;
use ReflectionProperty;
use RuntimeException;
use stdClass;
use TypeError;

class TranslationTranslatorTest extends TestCase
{
    public function testHasMethodReturnsFalseWhenReturnedTranslationIsNull(): void
    {
        $translator = $this->getMockBuilder(Translator::class)->onlyMethods(['get'])->setConstructorArgs([$this->getLoader(), 'en'])->getMock();
        $translator->expects($this->once())->method('get')->with($this->equalTo('foo'), $this->equalTo([]), $this->equalTo('bar'))->willReturn('foo');
        $this->assertFalse($translator->has('foo', 'bar'));

        $translator = $this->getMockBuilder(Translator::class)->onlyMethods(['get'])->setConstructorArgs([$this->getLoader(), 'en'])->getMock();
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

    public function testHasSuppressesMissingKeyCallbacks(): void
    {
        $translator = new Translator(new ArrayLoader, 'en');
        $calls = 0;
        $translator->handleMissingKeysUsing(function () use (&$calls): string {
            ++$calls;

            return 'handled';
        });

        $this->assertFalse($translator->has('messages.missing'));
        $this->assertSame(0, $calls);
    }

    public function testGetMethodProperlyLoadsAndRetrievesItem(): void
    {
        $translator = new Translator($this->getLoader(), 'en');
        $translator->getLoader()->shouldReceive('load')->once()->with('en', '*', '*')->andReturn([]);
        $translator->getLoader()->shouldReceive('load')->once()->with('en', 'bar', 'foo')->andReturn(['foo' => 'foo', 'baz' => 'breeze :foo', 'qux' => ['tree :foo', 'breeze :foo']]);
        $this->assertEquals(['tree bar', 'breeze bar'], $translator->get('foo::bar.qux', ['foo' => 'bar'], 'en'));
        $this->assertSame('breeze bar', $translator->get('foo::bar.baz', ['foo' => 'bar'], 'en'));
        $this->assertSame('foo', $translator->get('foo::bar.foo'));
    }

    public function testGetMethodProperlyLoadsAndRetrievesArrayItem(): void
    {
        $translator = new Translator($this->getLoader(), 'en');
        $translator->getLoader()->shouldReceive('load')->once()->with('en', '*', '*')->andReturn([]);
        $translator->getLoader()->shouldReceive('load')->once()->with('en', 'bar', 'foo')->andReturn(['foo' => 'foo', 'baz' => 'breeze :foo', 'qux' => ['tree :foo', 'breeze :foo', 'beep' => ['rock' => 'tree :foo']]]);
        $this->assertEquals(['foo' => 'foo', 'baz' => 'breeze bar', 'qux' => ['tree bar', 'breeze bar', 'beep' => ['rock' => 'tree bar']]], $translator->get('foo::bar', ['foo' => 'bar'], 'en'));
        $this->assertSame('breeze bar', $translator->get('foo::bar.baz', ['foo' => 'bar'], 'en'));
        $this->assertSame('foo', $translator->get('foo::bar.foo'));
    }

    public function testStringMethodProperlyLoadsAndRetrievesStringItem(): void
    {
        $translator = new Translator($this->getLoader(), 'en');
        $translator->getLoader()->shouldReceive('load')->once()->with('en', '*', '*')->andReturn([]);
        $translator->getLoader()->shouldReceive('load')->once()->with('en', 'bar', 'foo')->andReturn(['baz' => 'breeze :foo']);

        $this->assertSame('breeze bar', $translator->string('foo::bar.baz', ['foo' => 'bar'], 'en'));
    }

    public function testStringMethodThrowsExceptionForArrayItem(): void
    {
        $translator = new Translator($this->getLoader(), 'en');
        $translator->getLoader()->shouldReceive('load')->once()->with('en', '*', '*')->andReturn([]);
        $translator->getLoader()->shouldReceive('load')->once()->with('en', 'bar', 'foo')->andReturn(['baz' => ['breeze']]);
        $this->expectExceptionObject(new InvalidArgumentException('Translation value for key [foo::bar.baz] must be a string, array given.'));

        $translator->string('foo::bar.baz', [], 'en');
    }

    public function testArrayMethodProperlyLoadsAndRetrievesArrayItem(): void
    {
        $translator = new Translator($this->getLoader(), 'en');
        $translator->getLoader()->shouldReceive('load')->once()->with('en', '*', '*')->andReturn([]);
        $translator->getLoader()->shouldReceive('load')->once()->with('en', 'bar', 'foo')->andReturn(['baz' => ['breeze :foo']]);

        $this->assertSame(['breeze bar'], $translator->array('foo::bar.baz', ['foo' => 'bar'], 'en'));
    }

    public function testEmptyArrayItemDoesNotFallBackWhileEmptyGroupRemainsMissing(): void
    {
        $translator = new Translator($this->getLoader(), 'en');
        $translator->setFallback('lv');
        $translator->getLoader()->shouldReceive('load')->once()->with('en', '*', '*')->andReturn([]);
        $translator->getLoader()->shouldReceive('load')->once()->with('en', 'bar', 'foo')->andReturn(['empty' => []]);
        $translator->getLoader()->shouldReceive('load')->with('lv', 'bar', 'foo')->andReturn(['empty' => ['fallback']]);
        $translator->getLoader()->shouldReceive('load')->once()->with('en', 'missing', 'foo')->andReturn([]);
        $translator->getLoader()->shouldReceive('load')->once()->with('lv', 'missing', 'foo')->andReturn([]);

        $this->assertSame([], $translator->get('foo::bar.empty'));
        $this->assertTrue($translator->has('foo::bar.empty'));
        $this->assertSame([], $translator->array('foo::bar.empty'));
        $this->assertSame('foo::missing', $translator->get('foo::missing'));
    }

    public function testArrayMethodThrowsExceptionForStringItem(): void
    {
        $translator = new Translator($this->getLoader(), 'en');
        $translator->getLoader()->shouldReceive('load')->once()->with('en', '*', '*')->andReturn([]);
        $translator->getLoader()->shouldReceive('load')->once()->with('en', 'bar', 'foo')->andReturn(['baz' => 'breeze']);
        $this->expectExceptionObject(new InvalidArgumentException('Translation value for key [foo::bar.baz] must be an array, string given.'));

        $translator->array('foo::bar.baz', [], 'en');
    }

    public function testGetMethodForNonExistingReturnsSameKey(): void
    {
        $translator = new Translator($this->getLoader(), 'en');
        $translator->getLoader()->shouldReceive('load')->once()->with('en', '*', '*')->andReturn([]);
        $translator->getLoader()->shouldReceive('load')->once()->with('en', 'bar', 'foo')->andReturn(['foo' => 'foo', 'baz' => 'breeze :foo', 'qux' => ['tree :foo', 'breeze :foo']]);
        $translator->getLoader()->shouldReceive('load')->once()->with('en', 'unknown', 'foo')->andReturn([]);
        $this->assertSame('foo::unknown', $translator->get('foo::unknown', ['foo' => 'bar'], 'en'));
        $this->assertSame('foo::bar.unknown', $translator->get('foo::bar.unknown', ['foo' => 'bar'], 'en'));
        $this->assertSame('foo::unknown.bar', $translator->get('foo::unknown.bar'));
    }

    public function testTransMethodProperlyLoadsAndRetrievesItemWithHTMLInTheMessage(): void
    {
        $translator = new Translator($this->getLoader(), 'en');
        $translator->getLoader()->shouldReceive('load')->once()->with('en', '*', '*')->andReturn([]);
        $translator->getLoader()->shouldReceive('load')->once()->with('en', 'foo', '*')->andReturn(['bar' => 'breeze <p>test</p>']);
        $this->assertSame('breeze <p>test</p>', $translator->get('foo.bar', [], 'en'));
    }

    public function testGetMethodProperlyLoadsAndRetrievesItemWithCapitalization(): void
    {
        $translator = new Translator($this->getLoader(), 'en');
        $translator->getLoader()->shouldReceive('load')->once()->with('en', '*', '*')->andReturn([]);
        $translator->getLoader()->shouldReceive('load')->once()->with('en', 'bar', 'foo')->andReturn(['foo' => 'foo', 'baz' => 'breeze :0 :Foo :BAR']);
        $this->assertSame('breeze john Bar FOO', $translator->get('foo::bar.baz', ['john', 'foo' => 'bar', 'bar' => 'foo'], 'en'));
        $this->assertSame('foo', $translator->get('foo::bar.foo'));
    }

    public function testGetMethodProperlyLoadsAndRetrievesItemWithLongestReplacementsFirst(): void
    {
        $translator = new Translator($this->getLoader(), 'en');
        $translator->getLoader()->shouldReceive('load')->once()->with('en', '*', '*')->andReturn([]);
        $translator->getLoader()->shouldReceive('load')->once()->with('en', 'bar', 'foo')->andReturn(['foo' => 'foo', 'baz' => 'breeze :foo :foobar']);
        $this->assertSame('breeze bar taylor', $translator->get('foo::bar.baz', ['foo' => 'bar', 'foobar' => 'taylor'], 'en'));
        $this->assertSame('breeze foo bar baz taylor', $translator->get('foo::bar.baz', ['foo' => 'foo bar baz', 'foobar' => 'taylor'], 'en'));
        $this->assertSame('foo', $translator->get('foo::bar.foo'));
    }

    public function testGetMethodProperlyLoadsAndRetrievesItemForFallback(): void
    {
        $translator = new Translator($this->getLoader(), 'en');
        $translator->setFallback('lv');
        $translator->getLoader()->shouldReceive('load')->once()->with('en', '*', '*')->andReturn([]);
        $translator->getLoader()->shouldReceive('load')->once()->with('en', 'bar', 'foo')->andReturn([]);
        $translator->getLoader()->shouldReceive('load')->once()->with('lv', 'bar', 'foo')->andReturn(['foo' => 'foo', 'baz' => 'breeze :foo']);
        $this->assertSame('breeze bar', $translator->get('foo::bar.baz', ['foo' => 'bar'], 'en'));
        $this->assertSame('foo', $translator->get('foo::bar.foo'));
    }

    public function testFallbackLocaleCanBeReadAndChanged(): void
    {
        $translator = new Translator($this->getLoader(), 'en');

        $this->assertSame('', $translator->getFallback());

        $translator->setFallback('lv');

        $this->assertSame('lv', $translator->getFallback());
    }

    public function testBaseLocaleCanBeChangedWithoutReplacingCurrentRequestOverride(): void
    {
        $translator = new Translator($this->getLoader(), 'en');

        $translator->setBaseLocale('fr');

        $this->assertSame('fr', $translator->getLocale());

        $translator->setLocale('de');
        $translator->setBaseLocale('es');

        $this->assertSame('de', $translator->getLocale());
    }

    public function testGetDoesNotCallGetLineTwiceForMissingKeyWhenLocaleMatchesFallback(): void
    {
        $translator = $this->getMockBuilder(Translator::class)->onlyMethods(['getLine'])->setConstructorArgs([$this->getLoader(), 'en'])->getMock();
        $translator->setFallback('en');
        $translator->getLoader()->shouldReceive('load')->with('en', '*', '*')->andReturn([]);

        $translator->expects($this->once())->method('getLine')->with('*', 'messages', 'en', 'test', [])->willReturn(null);

        $translator->get('messages.test', [], 'en');
    }

    public function testGetMethodProperlyLoadsAndRetrievesItemForGlobalNamespace(): void
    {
        $translator = new Translator($this->getLoader(), 'en');
        $translator->getLoader()->shouldReceive('load')->once()->with('en', '*', '*')->andReturn([]);
        $translator->getLoader()->shouldReceive('load')->once()->with('en', 'foo', '*')->andReturn(['bar' => 'breeze :foo']);
        $this->assertSame('breeze bar', $translator->get('foo.bar', ['foo' => 'bar']));
    }

    public function testLinesAddedBeforeLoadingPreserveAndOverrideLoaderLines(): void
    {
        $loader = (new ArrayLoader)->addMessages('en', 'messages', [
            'file' => 'from file',
            'override' => 'from file',
        ]);
        $translator = new Translator($loader, 'en');

        $translator->addLines([
            'messages.added' => 'registered',
            'messages.override' => 'registered',
        ], 'en');

        $this->assertSame('from file', $translator->get('messages.file'));
        $this->assertSame('registered', $translator->get('messages.added'));
        $this->assertSame('registered', $translator->get('messages.override'));
    }

    public function testLinesAddedBeforeLoadingAreAppliedInCallOrder(): void
    {
        $translator = new Translator(new ArrayLoader, 'en');

        $translator->addLines(['messages.parent.child' => 'first'], 'en');
        $translator->addLines(['messages.parent' => 'replacement'], 'en');
        $translator->addLines(['messages.parent.child' => 'last'], 'en');

        $this->assertSame(['child' => 'last'], $translator->array('messages.parent'));
    }

    public function testEmptyGroupsAreLoadedOnceWithinAnExecution(): void
    {
        $translator = new Translator($this->getLoader(), 'en');
        $translator->getLoader()->shouldReceive('load')->once()->with('en', '*', '*')->andReturn([]);
        $translator->getLoader()->shouldReceive('load')->once()->with('en', 'messages', '*')->andReturn([]);

        $this->assertSame('messages.missing', $translator->get('messages.missing'));
        $this->assertSame('messages.other', $translator->get('messages.other'));
    }

    public function testArbitraryMissingGroupsRemainExecutionScoped(): void
    {
        $loader = new CountingTranslationLoader;
        $translator = new TranslationTranslatorStub($loader, 'en');

        for ($index = 0; $index < 1000; ++$index) {
            $translator->get("group{$index}.missing");
        }

        $this->assertSame([], $translator->loadedGroups());
        $this->assertSame(1001, $loader->loadCount);
        $this->assertSame(1001, $this->missingGroupCount($translator->missingGroups()));
    }

    public function testArbitraryMissingLocalesRemainExecutionScoped(): void
    {
        $loader = new CountingTranslationLoader;
        $translator = new TranslationTranslatorStub($loader, 'en');

        for ($index = 0; $index < 1000; ++$index) {
            $translator->setLocale("locale{$index}");
            $translator->get('messages.missing');
        }

        $this->assertSame([], $translator->loadedGroups());
        $this->assertSame(2000, $loader->loadCount);
        $this->assertSame(2000, $this->missingGroupCount($translator->missingGroups()));
    }

    public function testLinesAddedAfterAMissingGroupBecomeVisibleInCallOrder(): void
    {
        $translator = new Translator(new ArrayLoader, 'en');

        $this->assertSame('messages.parent', $translator->get('messages.parent'));

        $translator->addLines(['messages.parent.child' => 'first'], 'en');
        $translator->addLines(['messages.parent' => 'replacement'], 'en');
        $translator->addLines(['messages.parent.child' => 'last'], 'en');

        $this->assertSame(['child' => 'last'], $translator->array('messages.parent'));
    }

    public function testPendingLinesRemainWhenLoadingFails(): void
    {
        $exception = new RuntimeException('Unable to load translations.');
        $attempts = 0;
        $loader = m::mock(Loader::class);
        $loader->shouldReceive('load')->once()->with('en', '*', '*')->andReturn([]);
        $loader->shouldReceive('load')->twice()->with('en', 'messages', '*')->andReturnUsing(
            static function () use (&$attempts, $exception): array {
                if (++$attempts === 1) {
                    throw $exception;
                }

                return ['file' => 'from file'];
            }
        );
        $translator = new TranslationTranslatorStub($loader, 'en');
        $translator->addLines(['messages.added' => 'registered'], 'en');

        $thrown = null;

        try {
            $translator->get('messages.added');
            $this->fail('Expected the loader exception to be thrown.');
        } catch (RuntimeException $thrownException) {
            $thrown = $thrownException;
        }

        $this->assertSame($exception, $thrown);
        $this->assertFalse($translator->missingGroups()?->has('*', 'messages', 'en'));
        $this->assertSame('from file', $translator->get('messages.file'));
        $this->assertSame('registered', $translator->get('messages.added'));
    }

    public function testSetLoadedClearsPendingLinesAndMissingGroups(): void
    {
        $translator = new TranslationTranslatorStub(new ArrayLoader, 'en');

        $translator->get('missing.value');
        $translator->addLines(['messages.added' => 'registered'], 'en');
        $translator->setLoaded([
            '*' => [
                'other' => [
                    'en' => [],
                ],
            ],
        ]);

        $this->assertNull($translator->missingGroups());
        $this->assertSame('messages.added', $translator->get('messages.added'));
    }

    public function testChoicePassesCallerReplacementsToMissingKeyCallback(): void
    {
        $translator = new Translator(new ArrayLoader, 'en');
        $translator->setFallback('en');
        $received = null;
        $translator->handleMissingKeysUsing(function (string $key, array $replace, ?string $locale, bool $fallback) use (&$received): string {
            $received = [$key, $replace, $locale, $fallback];

            return '{1} Hello :name|[2,*] Hello :name, you have :count messages';
        });

        $this->assertSame(
            'Hello Taylor, you have 3 messages',
            $translator->choice('messages.greeting', 3, ['name' => 'Taylor'])
        );
        $this->assertSame(
            ['messages.greeting', ['name' => 'Taylor'], 'en', true],
            $received
        );
    }

    public function testChoicePreservesCallerCountForMissingKeyCallback(): void
    {
        $translator = new Translator(new ArrayLoader, 'en');
        $translator->setFallback('en');
        $received = null;
        $translator->handleMissingKeysUsing(function (string $key, array $replace) use (&$received): string {
            $received = $replace;

            return ':count item|:count items';
        });

        $this->assertSame('many items', $translator->choice('messages.items', 2, ['count' => 'many']));
        $this->assertSame(['count' => 'many'], $received);
    }

    public function testChoiceUsesTheCurrentLocaleWhenNoFallbackIsConfigured(): void
    {
        $translator = new Translator(new ArrayLoader, 'en');
        $receivedLocale = null;
        $translator->handleMissingKeysUsing(function (string $key, array $replace, ?string $locale) use (&$receivedLocale): string {
            $receivedLocale = $locale;

            return 'apple|apples';
        });

        $this->assertSame('apples', $translator->choice('messages.apples', 2));
        $this->assertSame('en', $receivedLocale);
    }

    public function testChoiceHandlesNumericArrayAndCountableValues(): void
    {
        $loader = (new ArrayLoader)->addMessages('en', 'messages', [
            'items' => '{0} none|{1} one|[2,*] :count items',
        ]);
        $translator = new Translator($loader, 'en');

        $this->assertSame('one', $translator->choice('messages.items', 1));
        $this->assertSame('2 items', $translator->choice('messages.items', 2.0));
        $this->assertSame('3 items', $translator->choice('messages.items', ['a', 'b', 'c']));
        $this->assertSame('3 items', $translator->choice('messages.items', new Collection(['a', 'b', 'c'])));
    }

    public function testChoiceUsesTheFallbackLocaleForSelection(): void
    {
        $loader = (new ArrayLoader)->addMessages('fr', 'messages', [
            'items' => '{0} aucun|{1} un|[2,*] :count éléments',
        ]);
        $translator = new Translator($loader, 'en');
        $translator->setFallback('fr');

        $this->assertSame('2 éléments', $translator->choice('messages.items', 2));
    }

    public function testChoiceAppliesReplacementsOnceAfterSelectingThePluralForm(): void
    {
        $loader = (new ArrayLoader)->addMessages('en', 'messages', [
            'greeting' => '{1} Hello :name|[2,*] Hello :name',
            'replacement' => '{1} :first|[2,*] :first',
        ]);
        $translator = new Translator($loader, 'en');

        $this->assertSame(
            'Hello Taylor | Hypervel',
            $translator->choice('messages.greeting', 2, ['name' => 'Taylor | Hypervel'])
        );
        $this->assertSame(
            ':second',
            $translator->choice('messages.replacement', 2, ['first' => ':second', 'second' => 'replaced twice'])
        );
    }

    public function testChoiceRequiresAStringTranslation(): void
    {
        $loader = (new ArrayLoader)->addMessages('en', 'messages', ['items' => ['one', 'many']]);
        $translator = new Translator($loader, 'en');

        $this->expectExceptionObject(new InvalidArgumentException('Translation value for key [messages.items] must be a string, array given.'));

        $translator->choice('messages.items', 2);
    }

    public function testGetJson(): void
    {
        $translator = new Translator($this->getLoader(), 'en');
        $translator->getLoader()->shouldReceive('load')->once()->with('en', '*', '*')->andReturn(['foo' => 'one']);
        $this->assertSame('one', $translator->get('foo'));
    }

    public function testGetJsonPreservesFalseyValuesAndHasAgreement(): void
    {
        $translator = new Translator($this->getLoader(), 'en');
        $translator->getLoader()->shouldReceive('load')->once()->with('en', '*', '*')->andReturn([
            'empty' => '',
            'zero' => '0',
            'items' => [],
        ]);

        $this->assertSame('', $translator->get('empty'));
        $this->assertSame('0', $translator->get('zero'));
        $this->assertSame([], $translator->get('items'));
        $this->assertTrue($translator->has('empty'));
        $this->assertTrue($translator->has('zero'));
        $this->assertTrue($translator->has('items'));
    }

    public function testNullJsonTranslationValueIsTreatedAsMissing(): void
    {
        $files = m::mock(Filesystem::class);
        $files->shouldReceive('exists')->once()->with(__DIR__ . '/en.json')->andReturn(true);
        $files->shouldReceive('get')->once()->with(__DIR__ . '/en.json')->andReturn('{"untranslated":null}');
        $files->shouldReceive('exists')->once()->with(__DIR__ . '/en/untranslated.php')->andReturn(false);

        $translator = new Translator(new FileLoader($files, __DIR__), 'en');

        $this->assertSame('untranslated', $translator->get('untranslated'));
        $this->assertFalse($translator->has('untranslated'));
    }

    public function testGetPreservesMixedArrayLeavesForJsonTranslations(): void
    {
        $object = new stdClass;
        $line = [
            'message' => 'Hello :name',
            7 => 42,
            'nested' => [
                'message' => 'Welcome :name',
                'float' => 1.5,
                'boolean' => false,
                'null' => null,
                'object' => $object,
            ],
        ];
        $expected = $line;
        $expected['message'] = 'Hello Taylor';
        $expected['nested']['message'] = 'Welcome Taylor';

        $translator = new Translator($this->getLoader(), 'en');
        $translator->getLoader()->shouldReceive('load')->once()->with('en', '*', '*')->andReturn(['payload' => $line]);

        $this->assertSame($line, $translator->get('payload'));
        $result = $translator->get('payload', ['name' => 'Taylor']);
        $this->assertSame($expected, $result);
        $this->assertSame($object, $result['nested']['object']);
        $this->assertSame('Hello :name', $line['message']);
    }

    public function testGetPreservesMixedArrayLeavesForGroupedTranslations(): void
    {
        $object = new stdClass;
        $line = [
            'message' => 'Hello :name',
            7 => 42,
            'nested' => [
                'message' => 'Welcome :name',
                'float' => 1.5,
                'boolean' => false,
                'null' => null,
                'object' => $object,
            ],
        ];
        $expected = $line;
        $expected['message'] = 'Hello Taylor';
        $expected['nested']['message'] = 'Welcome Taylor';

        $translator = new Translator($this->getLoader(), 'en');
        $translator->getLoader()->shouldReceive('load')->once()->with('en', '*', '*')->andReturn([]);
        $translator->getLoader()->shouldReceive('load')->once()->with('en', 'messages', '*')->andReturn(['payload' => $line]);

        $this->assertSame($line, $translator->get('messages.payload'));
        $result = $translator->get('messages.payload', ['name' => 'Taylor']);
        $this->assertSame($expected, $result);
        $this->assertSame($object, $result['nested']['object']);
        $this->assertSame('Hello :name', $line['message']);
    }

    public function testGetJsonReplaces(): void
    {
        $translator = new Translator($this->getLoader(), 'en');
        $translator->getLoader()->shouldReceive('load')->once()->with('en', '*', '*')->andReturn(['foo :i:c :u' => 'bar :i:c :u']);
        $this->assertSame('bar onetwo three', $translator->get('foo :i:c :u', ['i' => 'one', 'c' => 'two', 'u' => 'three']));
    }

    public function testGetJsonHasAtomicReplacements(): void
    {
        $translator = new Translator($this->getLoader(), 'en');
        $translator->getLoader()->shouldReceive('load')->once()->with('en', '*', '*')->andReturn(['Hello :foo!' => 'Hello :foo!']);
        $this->assertSame('Hello baz:bar!', $translator->get('Hello :foo!', ['foo' => 'baz:bar', 'bar' => 'abcdef']));
    }

    public function testGetJsonReplacesForAssociativeInput(): void
    {
        $translator = new Translator($this->getLoader(), 'en');
        $translator->getLoader()->shouldReceive('load')->once()->with('en', '*', '*')->andReturn(['foo :i :c' => 'bar :i :c']);
        $this->assertSame('bar eye see', $translator->get('foo :i :c', ['i' => 'eye', 'c' => 'see']));
    }

    public function testGetJsonPreservesOrder(): void
    {
        $translator = new Translator($this->getLoader(), 'en');
        $translator->getLoader()->shouldReceive('load')->once()->with('en', '*', '*')->andReturn(['to :name I give :greeting' => ':greeting :name']);
        $this->assertSame('Greetings David', $translator->get('to :name I give :greeting', ['name' => 'David', 'greeting' => 'Greetings']));
    }

    public function testGetJsonForNonExistingJsonKeyLooksForRegularKeys(): void
    {
        $translator = new Translator($this->getLoader(), 'en');
        $translator->getLoader()->shouldReceive('load')->once()->with('en', '*', '*')->andReturn([]);
        $translator->getLoader()->shouldReceive('load')->once()->with('en', 'foo', '*')->andReturn(['bar' => 'one']);
        $this->assertSame('one', $translator->get('foo.bar'));
    }

    public function testGetJsonForNonExistingJsonKeyLooksForRegularKeysAndReplace(): void
    {
        $translator = new Translator($this->getLoader(), 'en');
        $translator->getLoader()->shouldReceive('load')->once()->with('en', '*', '*')->andReturn([]);
        $translator->getLoader()->shouldReceive('load')->once()->with('en', 'foo', '*')->andReturn(['bar' => 'one :message']);
        $this->assertSame('one two', $translator->get('foo.bar', ['message' => 'two']));
    }

    public function testGetJsonForNonExistingReturnsSameKey(): void
    {
        $translator = new Translator($this->getLoader(), 'en');
        $translator->getLoader()->shouldReceive('load')->once()->with('en', '*', '*')->andReturn([]);
        $translator->getLoader()->shouldReceive('load')->once()->with('en', 'Foo that bar', '*')->andReturn([]);
        $this->assertSame('Foo that bar', $translator->get('Foo that bar'));
    }

    public function testGetJsonForNonExistingReturnsSameKeyAndReplaces(): void
    {
        $translator = new Translator($this->getLoader(), 'en');
        $translator->getLoader()->shouldReceive('load')->once()->with('en', '*', '*')->andReturn([]);
        $translator->getLoader()->shouldReceive('load')->once()->with('en', 'foo :message', '*')->andReturn([]);
        $this->assertSame('foo baz', $translator->get('foo :message', ['message' => 'baz']));
    }

    public function testEmptyFallbacks(): void
    {
        $translator = new Translator($this->getLoader(), 'en');
        $translator->getLoader()->shouldReceive('load')->once()->with('en', '*', '*')->andReturn([]);
        $translator->getLoader()->shouldReceive('load')->once()->with('en', 'foo :message', '*')->andReturn([]);
        $this->assertSame('foo ', $translator->get('foo :message', ['message' => null]));
    }

    public function testGetJsonReplacesWithStringable(): void
    {
        $translator = new Translator($this->getLoader(), 'en');
        $translator->getLoader()
            ->shouldReceive('load')
            ->once()
            ->with('en', '*', '*')
            ->andReturn(['test' => 'the date is :date']);

        $date = CarbonImmutable::createFromTimestamp(0);

        $this->assertSame(
            'the date is 1970-01-01 00:00:00',
            $translator->get('test', ['date' => $date])
        );

        $translator->stringable(function (CarbonImmutable $carbon) {
            return $carbon->format('jS M Y');
        });
        $this->assertSame(
            'the date is 1st Jan 1970',
            $translator->get('test', ['date' => $date])
        );
    }

    public function testGetJsonReplacesWithRegisteredStringableClass(): void
    {
        $translator = new Translator($this->getLoader(), 'en');
        $translator->getLoader()
            ->shouldReceive('load')
            ->once()
            ->with('en', '*', '*')
            ->andReturn(['test' => 'the date is :date']);

        $translator->stringable(
            CarbonImmutable::class,
            fn (CarbonImmutable $carbon): string => $carbon->format('jS M Y')
        );

        $this->assertSame(
            'the date is 1st Jan 1970',
            $translator->get('test', ['date' => CarbonImmutable::createFromTimestamp(0)])
        );
    }

    public function testStringableClassRequiresAHandler(): void
    {
        $translator = new Translator($this->getLoader(), 'en');

        $this->expectException(InvalidArgumentException::class);

        $translator->stringable(CarbonImmutable::class);
    }

    public function testStringableRejectsCallableArrays(): void
    {
        $translator = new Translator($this->getLoader(), 'en');

        $this->expectException(TypeError::class);

        $translator->stringable([$translator, 'get']);
    }

    public function testStringableRejectsInvokableObjects(): void
    {
        $translator = new Translator($this->getLoader(), 'en');
        $stringable = new class {
            public function __invoke(): string
            {
                return 'formatted';
            }
        };

        $this->expectException(TypeError::class);

        $translator->stringable($stringable);
    }

    public function testGetJsonReplacesWithEnums(): void
    {
        $translator = new Translator($this->getLoader(), 'en');
        $translator->getLoader()
            ->shouldReceive('load')
            ->once()
            ->with('en', '*', '*')
            ->andReturn([
                'string_backed_enum' => 'The release shipped in :month 2025',
                'int_backed_enum' => 'Stay tuned for version :version',
                'unit_enum' => ':person gets excited about every new release',
            ]);

        $this->assertSame(
            'The release shipped in February 2025',
            $translator->get('string_backed_enum', ['month' => Baz::February])
        );

        $this->assertSame(
            'Stay tuned for version 13',
            $translator->get('int_backed_enum', ['version' => Bar::Thirteen])
        );

        $this->assertSame(
            'Hosni gets excited about every new release',
            $translator->get('unit_enum', ['person' => Foo::Hosni])
        );
    }

    public function testTagReplacements(): void
    {
        $translator = new Translator($this->getLoader(), 'en');

        $translator->getLoader()->shouldReceive('load')->once()->with('en', '*', '*')->andReturn([]);
        $translator->getLoader()->shouldReceive('load')->once()->with('en', 'We have some nice <docs-link>documentation</docs-link>', '*')->andReturn([]);

        $this->assertSame(
            'We have some nice <a href="https://hypervel.org/docs">documentation</a>',
            $translator->get(
                'We have some nice <docs-link>documentation</docs-link>',
                [
                    'docs-link' => fn ($children) => "<a href=\"https://hypervel.org/docs\">{$children}</a>",
                ]
            )
        );
    }

    public function testTagReplacementsHandleMultipleOfSameTag(): void
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

    public function testDetermineLocalesUsingMethod(): void
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

    public function testConfiguredInvalidLocaleIsRejectedBeforeFilesystemAccess(): void
    {
        $files = m::mock(Filesystem::class);
        $files->shouldReceive('exists')->never();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid characters present in locale.');

        new Translator(new FileLoader($files, __DIR__), '.');
    }

    public function testExplicitInvalidLocaleIsRejectedBeforeFilesystemAccess(): void
    {
        $files = m::mock(Filesystem::class);
        $files->shouldReceive('exists')->never();
        $translator = new Translator(new FileLoader($files, __DIR__), 'en');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid characters present in locale.');

        $translator->get('messages.welcome', [], 'en/US');
    }

    public function testInvalidFallbackLocaleIsRejectedBeforeItsFilesystemAccess(): void
    {
        $files = m::mock(Filesystem::class);
        $files->shouldReceive('exists')->never();
        $translator = new Translator(new FileLoader($files, __DIR__), 'en');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid characters present in locale.');

        $translator->setFallback('../fr');
    }

    public function testInvalidLocaleFromResolverIsRejectedBeforeItsFilesystemAccess(): void
    {
        $files = m::mock(Filesystem::class);
        $files->shouldReceive('exists')->once()->with(__DIR__ . '/en.json')->andReturn(false);
        $translator = new Translator(new FileLoader($files, __DIR__), 'en');
        $translator->determineLocalesUsing(static fn (array $locales): array => ['en\US']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid characters present in locale.');

        $translator->get('messages.welcome');
    }

    public function testLocaleSettersRejectInvalidLocaleImmediately(): void
    {
        $loader = m::mock(Loader::class);
        $loader->shouldReceive('load')->never();
        $translator = new Translator($loader, 'en');

        foreach (['setLocale', 'setBaseLocale'] as $method) {
            try {
                $translator->{$method}('..');
                $this->fail("Expected {$method} to reject the invalid locale.");
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('Invalid characters present in locale.', $exception->getMessage());
            }
        }
    }

    public function testMissingKeyCallbackDoesNotRecurse(): void
    {
        $translator = new Translator(new ArrayLoader, 'en');
        $calls = 0;

        $translator->handleMissingKeysUsing(function (string $key) use ($translator, &$calls): string {
            ++$calls;

            return 'handled:' . $translator->get($key);
        });

        $this->assertSame('handled:messages.missing', $translator->get('messages.missing'));
        $this->assertSame(1, $calls);
    }

    public function testGetPassesCallerReplacementsToMissingKeyCallback(): void
    {
        $translator = new Translator(new ArrayLoader, 'en');
        $received = null;
        $translator->handleMissingKeysUsing(function (string $key, array $replace, ?string $locale, bool $fallback) use (&$received): string {
            $received = [$key, $replace, $locale, $fallback];

            return 'Hello :name';
        });

        $this->assertSame('Hello Taylor', $translator->get('messages.greeting', ['name' => 'Taylor'], 'fr', false));
        $this->assertSame(
            ['messages.greeting', ['name' => 'Taylor'], 'fr', false],
            $received
        );
    }

    public function testFlushStateClearsMacros(): void
    {
        new TranslationTranslatorStub(new ArrayLoader, 'en');
        $nextTranslatorId = TranslationTranslatorStub::nextTranslatorId();
        Translator::macro('translationStaticStateProbe', static fn (): string => 'ok');

        $this->assertTrue(Translator::hasMacro('translationStaticStateProbe'));

        Translator::flushState();

        $this->assertFalse(Translator::hasMacro('translationStaticStateProbe'));
        $this->assertSame($nextTranslatorId, TranslationTranslatorStub::nextTranslatorId());
    }

    public function testDoubleUnderscoreHelperReturnsNullWhenKeyIsNull(): void
    {
        $this->assertNull(__(null));
    }

    protected function getLoader(): Loader
    {
        return m::mock(Loader::class);
    }

    protected function missingGroupCount(?MissingTranslationGroups $missingTranslationGroups): int
    {
        if ($missingTranslationGroups === null) {
            return 0;
        }

        $groups = (new ReflectionProperty(MissingTranslationGroups::class, 'groups'))
            ->getValue($missingTranslationGroups);

        return array_sum(array_map(
            static fn (array $namespaceGroups): int => array_sum(array_map('count', $namespaceGroups)),
            $groups
        ));
    }
}

class TranslationTranslatorStub extends Translator
{
    public function loadedGroups(): array
    {
        return $this->loaded;
    }

    public function missingGroups(): ?MissingTranslationGroups
    {
        return $this->missingTranslationGroups();
    }

    public static function nextTranslatorId(): int
    {
        return self::$nextTranslatorId;
    }
}

class CountingTranslationLoader extends ArrayLoader
{
    public int $loadCount = 0;

    public function load(string $locale, string $group, ?string $namespace = null): array
    {
        ++$this->loadCount;

        return parent::load($locale, $group, $namespace);
    }
}
