<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Log\ContextTest;

use Closure;
use Exception;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\Log\ContextLogProcessor;
use Hypervel\Log\Context\Events\ContextDehydrating as Dehydrating;
use Hypervel\Log\Context\Events\ContextHydrated as Hydrated;
use Hypervel\Log\Context\Repository;
use Hypervel\Support\Facades\Context;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Log;
use Hypervel\Support\Str;
use Hypervel\Testbench\TestCase;
use Monolog\LogRecord;
use Override;
use RuntimeException;

class ContextTest extends TestCase
{
    protected string $logPath;

    /**
     * Configure the test environment.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $this->logPath = $app->storagePath('logs/context-test.log');
        $app->make('config')->set('logging.channels.single.path', $this->logPath);
    }

    /**
     * Clean up the test environment.
     */
    protected function tearDown(): void
    {
        MyAddContextProcessor::$wasConstructed = false;

        if (is_file($this->logPath)) {
            unlink($this->logPath);
        }

        parent::tearDown();
    }

    public function testItCanSetValues(): void
    {
        $values = [
            'string' => 'string',
            'bool' => false,
            'int' => 5,
            'float' => 5.5,
            'null' => null,
            'array' => [1, 2, 3],
            'hash' => ['foo' => 'bar'],
            'object' => (object) ['foo' => 'bar'],
            'enum' => Suit::Clubs,
            'backed_enum' => StringBackedSuit::Clubs,
        ];

        foreach ($values as $type => $value) {
            Context::add($type, $value);
        }

        foreach ($values as $type => $value) {
            $this->assertSame($value, Context::get($type));
        }
    }

    public function testItCanAddValuesWhenNotAlreadyPresent(): void
    {
        Context::addIf('foo', 1);
        $this->assertSame(1, Context::get('foo'));

        Context::addIf('foo', 2);
        $this->assertSame(1, Context::get('foo'));
    }

    public function testItCanListenToTheHydratingEvent(): void
    {
        Context::add('one', 1);
        Context::add('two', 2);
        Context::hydrated(function (Repository $context): void {
            Context::add('two', 99);
            Context::add('three', 3);
        });
        Event::dispatch(new Hydrated(Context::getFacadeRoot()));

        $this->assertSame(1, Context::get('one'));
        $this->assertSame(99, Context::get('two'));
        $this->assertSame(3, Context::get('three'));
    }

    public function testItCanListenToTheDehydratedEvent(): void
    {
        Context::add('one', 1);
        Context::add('two', 2);
        Context::dehydrating(function (Repository $context): void {
            Context::add('two', 99);
            Context::add('three', 3);
        });
        Event::dispatch(new Dehydrating(Context::getFacadeRoot()));

        $this->assertSame(1, Context::get('one'));
        $this->assertSame(99, Context::get('two'));
        $this->assertSame(3, Context::get('three'));
    }

    public function testItCanModifyContextWhileDehydratingWithoutImpactingGlobalInstance(): void
    {
        Context::add('one', 1);
        Context::dehydrating(function (Repository $context): void {
            $context->add('one', 99);
        });

        $dehydrated = Context::dehydrate();
        $this->assertSame(1, Context::get('one'));

        Context::hydrate($dehydrated);
        $this->assertSame(99, Context::get('one'));
    }

    public function testDehydrateReturnsNullWhenEmpty(): void
    {
        $this->assertNull(Context::dehydrate());
    }

    public function testHydratingNullTriggersHydratingEvent(): void
    {
        $called = false;
        Context::hydrated(function () use (&$called): void {
            $called = true;
        });

        Context::hydrate(null);

        $this->assertTrue($called);
    }

    public function testItCanSerializeValues(): void
    {
        Context::add([
            'string' => 'string',
            'bool' => false,
            'int' => 5,
            'float' => 5.5,
            'null' => null,
            'array' => [1, 2, 3],
            'hash' => ['foo' => 'bar'],
            'object' => (object) ['foo' => 'bar'],
            'enum' => Suit::Clubs,
            'backed_enum' => StringBackedSuit::Clubs,
        ]);
        Context::addHidden('number', 55);

        $dehydrated = Context::dehydrate();

        $this->assertSame([
            'data' => [
                'string' => 's:6:"string";',
                'bool' => 'b:0;',
                'int' => 'i:5;',
                'float' => 'd:5.5;',
                'null' => 'N;',
                'array' => 'a:3:{i:0;i:1;i:1;i:2;i:2;i:3;}',
                'hash' => 'a:1:{s:3:"foo";s:3:"bar";}',
                'object' => 'O:8:"stdClass":1:{s:3:"foo";s:3:"bar";}',
                'enum' => serialize(Suit::Clubs),
                'backed_enum' => serialize(StringBackedSuit::Clubs),
            ],
            'hidden' => [
                'number' => 'i:55;',
            ],
        ], $dehydrated);

        Context::flush();
        $this->assertNull(Context::get('string'));

        Context::hydrate($dehydrated);

        $this->assertSame('string', Context::get('string'));
        $this->assertFalse(Context::get('bool'));
        $this->assertSame(5, Context::get('int'));
        $this->assertSame(5.5, Context::get('float'));
        $this->assertNull(Context::get('null'));
        $this->assertSame([1, 2, 3], Context::get('array'));
        $this->assertSame(['foo' => 'bar'], Context::get('hash'));
        $this->assertEquals(Context::get('object'), (object) ['foo' => 'bar']);
        $this->assertSame(Suit::Clubs, Context::get('enum'));
        $this->assertSame(StringBackedSuit::Clubs, Context::get('backed_enum'));
        $this->assertSame(55, Context::getHidden('number'));
    }

    public function testItCanPushToList(): void
    {
        Context::push('breadcrumbs', 'foo');
        Context::push('breadcrumbs', 'bar');
        Context::push('breadcrumbs', 'baz', 'qux');

        $this->assertSame(['foo', 'bar', 'baz', 'qux'], Context::get('breadcrumbs'));
    }

    public function testThrowsWhenPushingToNonArray(): void
    {
        Context::add('breadcrumbs', 'foo');

        $this->expectExceptionObject(new RuntimeException('Unable to push value onto context stack for key [breadcrumbs].'));
        Context::push('breadcrumbs', 'bar');
    }

    public function testThrowsWhenPushingToNonListArray(): void
    {
        Context::add('breadcrumbs', ['foo' => 'bar']);

        $this->expectExceptionObject(new RuntimeException('Unable to push value onto context stack for key [breadcrumbs].'));
        Context::push('breadcrumbs', 'bar');
    }

    public function testItCanPopFromList(): void
    {
        Context::push('breadcrumbs', 'foo', 'bar');

        $this->assertSame('bar', Context::pop('breadcrumbs'));
        $this->assertSame('foo', Context::pop('breadcrumbs'));
        $this->assertSame([], Context::get('breadcrumbs'));
    }

    public function testThrowsWhenPoppingFromEmptyList(): void
    {
        Context::push('breadcrumbs', 'bar');
        Context::pop('breadcrumbs');

        $this->expectExceptionObject(new RuntimeException('Unable to pop value from context stack for key [breadcrumbs].'));

        Context::pop('breadcrumbs');
    }

    public function testThrowsWhenPoppingFromNonListArray(): void
    {
        Context::add('breadcrumbs', ['foo' => 'bar']);

        $this->expectExceptionObject(new RuntimeException('Unable to pop value from context stack for key [breadcrumbs].'));
        Context::pop('breadcrumbs');
    }

    public function testItCanPopFromHiddenList(): void
    {
        Context::pushHidden('breadcrumbs', 'foo', 'bar');

        $this->assertSame('bar', Context::popHidden('breadcrumbs'));
        $this->assertSame('foo', Context::popHidden('breadcrumbs'));
        $this->assertSame([], Context::getHidden('breadcrumbs'));
    }

    public function testThrowsWhenPoppingFromEmptyHiddenList(): void
    {
        Context::pushHidden('breadcrumbs', 'bar');
        Context::popHidden('breadcrumbs');

        $this->expectExceptionObject(new RuntimeException('Unable to pop value from hidden context stack for key [breadcrumbs].'));

        Context::popHidden('breadcrumbs');
    }

    public function testThrowsWhenPoppingFromHiddenNonListArray(): void
    {
        Context::addHidden('breadcrumbs', ['foo' => 'bar']);

        $this->expectExceptionObject(new RuntimeException('Unable to pop value from hidden context stack for key [breadcrumbs].'));
        Context::popHidden('breadcrumbs');
    }

    public function testItCanCheckIfContextHasBeenSet(): void
    {
        Context::add('foo', 'bar');
        Context::add('null', null);

        $this->assertTrue(Context::has('foo'));
        $this->assertTrue(Context::has('null'));
        $this->assertFalse(Context::has('unset'));
    }

    public function testItCanCheckIfContextIsMissing(): void
    {
        Context::add('foo', 'bar');

        $this->assertTrue(Context::missing('lorem'));
        $this->assertFalse(Context::missing('foo'));
    }

    public function testItCanCheckIfValueIsInContextStack(): void
    {
        Context::push('foo', 'bar', 'lorem');

        $this->assertTrue(Context::stackContains('foo', 'bar'));
        $this->assertTrue(Context::stackContains('foo', 'lorem'));
        $this->assertFalse(Context::stackContains('foo', 'doesNotExist'));
    }

    public function testItCanCheckIfValueIsInContextStackWithClosures(): void
    {
        Context::push('foo', 'bar', ['lorem'], 123);
        Context::pushHidden('baz');

        $this->assertTrue(Context::stackContains('foo', fn (mixed $value): bool => $value === 'bar'));
        $this->assertFalse(Context::stackContains('foo', fn (mixed $value): bool => $value === 'baz'));
    }

    public function testItCanCheckIfValueIsInHiddenContextStack(): void
    {
        Context::pushHidden('foo', 'bar', 'lorem');

        $this->assertTrue(Context::hiddenStackContains('foo', 'bar'));
        $this->assertTrue(Context::hiddenStackContains('foo', 'lorem'));
        $this->assertFalse(Context::hiddenStackContains('foo', 'doesNotExist'));
    }

    public function testItCanCheckIfValueIsInHiddenContextStackWithClosures(): void
    {
        Context::pushHidden('foo', 'baz');
        Context::push('foo', 'bar', ['lorem'], 123);

        $this->assertTrue(Context::hiddenStackContains('foo', fn (mixed $value): bool => $value === 'baz'));
        $this->assertFalse(Context::hiddenStackContains('foo', fn (mixed $value): bool => $value === 'bar'));
    }

    public function testItCannotCheckIfHiddenValueIsInNonHiddenContextStack(): void
    {
        Context::pushHidden('foo', 'bar', 'lorem');

        $this->assertFalse(Context::stackContains('foo', 'bar'));
    }

    public function testItCanGetAllValues(): void
    {
        Context::add('foo', 'bar');
        Context::add('null', null);

        $this->assertSame([
            'foo' => 'bar',
            'null' => null,
        ], Context::all());
    }

    public function testItSilentlyIgnoresUnsetValues(): void
    {
        $this->assertNull(Context::get('foo'));
        $this->assertFalse(Context::has('foo'));
        $this->assertSame([], Context::all());
    }

    public function testItIsSimpleKeyValueSystem(): void
    {
        Context::add('parent.child', 5);

        $this->assertNull(Context::get('parent'));
        $this->assertSame(5, Context::get('parent.child'));
    }

    public function testItCanRetrieveSubsetOfContext(): void
    {
        Context::add('parent.child.1', 5);
        Context::add('parent.child.2', 6);
        Context::add('another', 7);

        $this->assertSame([
            'parent.child.1' => 5,
            'parent.child.2' => 6,
        ], Context::only([
            'parent.child.1',
            'parent.child.2',
        ]));
    }

    public function testItCanExcludeSubsetOfContext(): void
    {
        Context::add('parent.child.1', 5);
        Context::add('parent.child.2', 6);
        Context::add('another', 7);

        $this->assertSame([
            'another' => 7,
        ], Context::except([
            'parent.child.1',
            'parent.child.2',
        ]));
    }

    public function testItCanExcludeSubsetOfHiddenContext(): void
    {
        Context::addHidden('parent.child.1', 5);
        Context::addHidden('parent.child.2', 6);
        Context::addHidden('another', 7);

        $this->assertSame([
            'another' => 7,
        ], Context::exceptHidden([
            'parent.child.1',
            'parent.child.2',
        ]));
    }

    public function testItAddsContextToLogging(): void
    {
        $path = $this->logPath;
        file_put_contents($path, '');
        Str::createUuidsUsingSequence(['550e8400-e29b-41d4-a716-446655440000']);

        Context::add('trace_id', (string) Str::uuid());
        Context::add('foo.bar', 123);
        Context::push('bar.baz', 456);
        Context::push('bar.baz', 789);

        Log::channel('single')->info('My name is {name}', [
            'name' => 'Tim',
            'framework' => 'Hypervel',
        ]);
        $log = Str::after(file_get_contents($path), '] ');

        $this->assertSame('testing.INFO: My name is Tim {"name":"Tim","framework":"Hypervel"} {"trace_id":"550e8400-e29b-41d4-a716-446655440000","foo.bar":123,"bar.baz":[456,789]}', trim($log));
    }

    public function testItDoesntOverrideLogInstanceContext(): void
    {
        $path = $this->logPath;
        file_put_contents($path, '');

        Context::add('name', 'James');

        Log::channel('single')->info('My name is {name}', [
            'name' => 'Tim',
        ]);
        $log = Str::after(file_get_contents($path), '] ');

        $this->assertSame('testing.INFO: My name is Tim {"name":"Tim"} {"name":"James"}', trim($log));
    }

    public function testItDoesntAllowContextToBeUsedAsParameters(): void
    {
        $path = $this->logPath;
        file_put_contents($path, '');

        Context::add('name', 'James');

        Log::channel('single')->info('My name is {name}');
        $log = Str::after(file_get_contents($path), '] ');

        $this->assertSame('testing.INFO: My name is {name}  {"name":"James"}', trim($log));
    }

    public function testDoesNotAddHiddenContextToLogging(): void
    {
        $path = $this->logPath;
        file_put_contents($path, '');

        Context::addHidden('hidden_data', 'hidden_data');

        Log::channel('single')->info('My name is {name}', [
            'name' => 'Tim',
            'framework' => 'Hypervel',
        ]);
        $log = Str::after(file_get_contents($path), '] ');

        $this->assertStringNotContainsString('hidden_data', trim($log));
    }

    public function testItCanAddHidden(): void
    {
        Context::addHidden('foo', 'data');

        $this->assertFalse(Context::has('foo'));
        $this->assertTrue(Context::hasHidden('foo'));
        $this->assertNull(Context::get('foo'));
        $this->assertSame('data', Context::getHidden('foo'));
        $this->assertSame(['foo' => 'data'], Context::onlyHidden(['foo']));

        Context::forgetHidden('foo');

        $this->assertFalse(Context::has('foo'));
        $this->assertFalse(Context::hasHidden('foo'));
        $this->assertNull(Context::get('foo'));
        $this->assertNull(Context::getHidden('foo'));

        Context::pushHidden('foo', 1);
        Context::pushHidden('foo', 2);
        $this->assertSame([1, 2], Context::getHidden('foo'));

        Context::addHidden('foo', 'bar');

        $this->expectExceptionObject(new RuntimeException('Unable to push value onto hidden context stack for key [foo].'));
        Context::pushHidden('foo', 2);
    }

    public function testItCanPull(): void
    {
        Context::add('foo', 'data');

        $this->assertSame('data', Context::pull('foo'));
        $this->assertNull(Context::get('foo'));

        Context::addHidden('foo', 'data');

        $this->assertSame('data', Context::pullHidden('foo'));
        $this->assertNull(Context::getHidden('foo'));
    }

    public function testItAddsContextToLoggedExceptions(): void
    {
        $path = $this->logPath;
        file_put_contents($path, '');
        Str::createUuidsUsingSequence(['550e8400-e29b-41d4-a716-446655440000']);

        Context::add('trace_id', (string) Str::uuid());
        Context::add('foo.bar', 123);
        Context::push('bar.baz', 456);
        Context::push('bar.baz', 789);

        $this->app->make(ExceptionHandler::class)->report(new Exception('Whoops!'));
        $log = Str::after(file_get_contents($path), '] ');

        $this->assertStringEndsWith(' {"trace_id":"550e8400-e29b-41d4-a716-446655440000","foo.bar":123,"bar.baz":[456,789]}', Str::trim($log));
    }

    public function testScopeSetsKeysAndRestores(): void
    {
        $contextInClosure = [];
        $callback = function () use (&$contextInClosure): never {
            $contextInClosure = ['data' => Context::all(), 'hidden' => Context::allHidden()];

            throw new Exception('test_with_sets_keys_and_restores');
        };

        Context::add('key1', 'value1');
        Context::add('key2', 123);
        Context::addHidden([
            'hiddenKey1' => 'hello',
            'hiddenKey2' => 'world',
        ]);

        try {
            Context::scope(
                $callback,
                ['key1' => 'with', 'key3' => 'also-with'],
                ['hiddenKey3' => 'foobar'],
            );

            $this->fail('No exception was thrown.');
        } catch (Exception) {
        }

        $this->assertEqualsCanonicalizing([
            'data' => [
                'key1' => 'with',
                'key2' => 123,
                'key3' => 'also-with',
            ],
            'hidden' => [
                'hiddenKey1' => 'hello',
                'hiddenKey2' => 'world',
                'hiddenKey3' => 'foobar',
            ],
        ], $contextInClosure);

        $this->assertEqualsCanonicalizing([
            'key1' => 'value1',
            'key2' => 123,
        ], Context::all());
        $this->assertEqualsCanonicalizing([
            'hiddenKey1' => 'hello',
            'hiddenKey2' => 'world',
        ], Context::allHidden());
    }

    public function testUsesClosureForContextProcessor(): void
    {
        $path = $this->logPath;
        file_put_contents($path, '');

        $this->app->bind(
            ContextLogProcessor::class,
            fn (): Closure => function (LogRecord $record): LogRecord {
                $logChannel = Context::getHidden('log_channel_name');

                return $record->with(
                    // allow overriding the context from what's been set on the log
                    context: array_merge(Context::all(), $record->context),
                    // use the log channel we've set in context, or fallback to the current channel
                    channel: $logChannel ?? $record->channel,
                );
            }
        );

        Context::addHidden('log_channel_name', 'closure-test');
        Context::add(['value_from_context' => 'hello']);

        Log::info('This is an info log.', ['value_from_log_info_context' => 'foo']);

        $log = Str::after(file_get_contents($path), '] ');
        $this->assertSame('closure-test.INFO: This is an info log. {"value_from_context":"hello","value_from_log_info_context":"foo"}', Str::trim($log));
    }

    public function testCanRebindToSeparateClass(): void
    {
        $path = $this->logPath;
        file_put_contents($path, '');

        $this->app->bind(ContextLogProcessor::class, MyAddContextProcessor::class);

        Context::add(['this-will-be-included' => false]);

        Log::info('This is an info log.', ['value_from_log_info_context' => 'foo']);
        $log = Str::after(file_get_contents($path), '] ');
        $this->assertSame(
            'testing.INFO: This is an info log. {"value_from_log_info_context":"foo","inside of MyAddContextProcessor":true}',
            Str::trim($log)
        );
        $this->assertTrue(MyAddContextProcessor::$wasConstructed);
    }

    public function testItIncrementsACounter(): void
    {
        Context::increment('foo');
        $this->assertSame(1, Context::get('foo'));

        Context::increment('foo');
        $this->assertSame(2, Context::get('foo'));
    }

    public function testItCustomIncrementsACounter(): void
    {
        Context::increment('foo', 2);
        $this->assertSame(2, Context::get('foo'));

        Context::increment('foo', 3);
        $this->assertSame(5, Context::get('foo'));
    }

    public function testItDecrementsACounter(): void
    {
        Context::increment('foo');
        Context::decrement('foo');
        $this->assertSame(0, Context::get('foo'));
    }

    public function testItCustomDecrementsACounter(): void
    {
        Context::increment('foo', 2);
        Context::decrement('foo', 2);
        $this->assertSame(0, Context::get('foo'));
    }

    public function testItRemembersAValue(): void
    {
        $this->assertSame(1, Context::remember('int', 1));

        $closureRunCount = 0;
        $closure = function () use (&$closureRunCount): string {
            ++$closureRunCount;

            return 'bar';
        };

        $this->assertSame('bar', Context::remember('foo', $closure));
        $this->assertSame('bar', Context::get('foo'));

        Context::remember('foo', $closure);
        $this->assertSame(1, $closureRunCount);
    }

    public function testItRemembersAHiddenValue(): void
    {
        $this->assertSame(1, Context::rememberHidden('int', 1));

        $closureRunCount = 0;
        $closure = function () use (&$closureRunCount): string {
            ++$closureRunCount;

            return 'bar';
        };

        $this->assertSame('bar', Context::rememberHidden('foo', $closure));
        $this->assertSame('bar', Context::getHidden('foo'));

        Context::rememberHidden('foo', $closure);
        $this->assertSame(1, $closureRunCount);
    }

    public function testReportHelperAddsContextToExceptionContext(): void
    {
        $path = $this->logPath;
        file_put_contents($path, '');

        report(new Exception('Whoops!'), ['foo' => 'bar', 'baz' => 123]);

        $log = Str::after(file_get_contents($path), '] ');

        $this->assertStringContainsString('"foo":"bar"', $log);
        $this->assertStringContainsString('"baz":123', $log);
    }

    public function testReportContextIsSeparateFromGlobalContext(): void
    {
        $path = $this->logPath;
        file_put_contents($path, '');

        Context::add('global_key', 'global_value');

        report(new Exception('Whoops!'), ['report_key' => 'report_value']);

        $log = Str::after(file_get_contents($path), '] ');

        // Report context is in exception context (before the global context extra)
        $this->assertStringContainsString('"report_key":"report_value"', $log);
        // Global context is in extra (at the end of the log line)
        $this->assertStringEndsWith(' {"global_key":"global_value"}', trim($log));
    }

    public function testReportHelperWithoutContextDoesNotAddContext(): void
    {
        $path = $this->logPath;
        file_put_contents($path, '');

        report(new Exception('Whoops!'));

        $log = Str::after(file_get_contents($path), '] ');

        $this->assertStringNotContainsString('"foo"', $log);
        $this->assertStringNotContainsString('"bar"', $log);
    }

    public function testReportIfHelperAddsContextWhenConditionIsTrue(): void
    {
        $path = $this->logPath;
        file_put_contents($path, '');

        report_if(true, new Exception('Whoops!'), ['foo' => 'bar']);

        $log = Str::after(file_get_contents($path), '] ');

        $this->assertStringContainsString('"foo":"bar"', $log);
    }

    public function testReportUnlessHelperAddsContextWhenConditionIsFalse(): void
    {
        $path = $this->logPath;
        file_put_contents($path, '');

        report_unless(false, new Exception('Whoops!'), ['foo' => 'bar']);

        $log = Str::after(file_get_contents($path), '] ');

        $this->assertStringContainsString('"foo":"bar"', $log);
    }

    public function testReportContextDoesNotLeakToGlobalContext(): void
    {
        report(new Exception('Whoops!'), ['leaked' => 'value']);

        $this->assertNull(Context::get('leaked'));
    }
}

enum Suit
{
    case Hearts;
    case Diamonds;
    case Clubs;
    case Spades;
}

enum StringBackedSuit: string
{
    case Hearts = 'hearts';
    case Diamonds = 'diamonds';
    case Clubs = 'clubs';
    case Spades = 'spades';
}

class MyAddContextProcessor implements ContextLogProcessor
{
    public static bool $wasConstructed = false;

    /**
     * Create the context processor.
     */
    public function __construct()
    {
        self::$wasConstructed = true;
    }

    /**
     * Add context to the log record.
     */
    #[Override]
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(context: array_merge($record->context, ['inside of MyAddContextProcessor' => true]));
    }
}
