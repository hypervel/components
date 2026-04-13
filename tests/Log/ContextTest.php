<?php

declare(strict_types=1);

namespace Hypervel\Tests\Log;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Log\Context\Events\ContextDehydrating;
use Hypervel\Log\Context\Events\ContextHydrated;
use Hypervel\Log\Context\Repository;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;
use stdClass;

/**
 * @internal
 * @coversNothing
 */
class ContextTest extends TestCase
{
    protected Repository $context;

    protected Dispatcher $events;

    protected function setUp(): void
    {
        parent::setUp();

        $this->events = m::mock(Dispatcher::class);
        $this->events->shouldReceive('hasListeners')->byDefault()->andReturn(true);
        $this->events->shouldReceive('listen')->byDefault();
        $this->events->shouldReceive('dispatch')->byDefault();
        $this->context = new Repository($this->events);
    }

    // =========================================================================
    // Basic data operations
    // =========================================================================

    public function testItCanAddAndGetValues()
    {
        $this->context->add('string', 'hello');
        $this->context->add('int', 42);
        $this->context->add('bool', true);
        $this->context->add('null', null);
        $this->context->add('array', [1, 2, 3]);
        $object = new stdClass;
        $this->context->add('object', $object);

        $this->assertSame('hello', $this->context->get('string'));
        $this->assertSame(42, $this->context->get('int'));
        $this->assertTrue($this->context->get('bool'));
        $this->assertNull($this->context->get('null'));
        $this->assertSame([1, 2, 3], $this->context->get('array'));
        $this->assertSame($object, $this->context->get('object'));
    }

    public function testItCanAddMultipleValuesAtOnce()
    {
        $this->context->add(['key1' => 'val1', 'key2' => 'val2']);

        $this->assertSame('val1', $this->context->get('key1'));
        $this->assertSame('val2', $this->context->get('key2'));
    }

    public function testItCanAddValuesWhenNotAlreadyPresent()
    {
        $this->context->addIf('key', 'first');
        $this->assertSame('first', $this->context->get('key'));

        $this->context->addIf('key', 'second');
        $this->assertSame('first', $this->context->get('key'));
    }

    public function testItCanCheckIfKeyExists()
    {
        $this->assertFalse($this->context->has('key'));
        $this->assertTrue($this->context->missing('key'));

        $this->context->add('key', 'value');

        $this->assertTrue($this->context->has('key'));
        $this->assertFalse($this->context->missing('key'));
    }

    public function testItCanCheckIfKeyExistsWithNullValue()
    {
        $this->context->add('key', null);

        $this->assertTrue($this->context->has('key'));
    }

    public function testItCanGetAllValues()
    {
        $this->context->add(['a' => 1, 'b' => 2]);

        $this->assertSame(['a' => 1, 'b' => 2], $this->context->all());
    }

    public function testItCanGetSubsetOfValues()
    {
        $this->context->add(['a' => 1, 'b' => 2, 'c' => 3]);

        $this->assertSame(['a' => 1, 'c' => 3], $this->context->only(['a', 'c']));
    }

    public function testItCanExcludeSubsetOfValues()
    {
        $this->context->add(['a' => 1, 'b' => 2, 'c' => 3]);

        $this->assertSame(['b' => 2, 'c' => 3], $this->context->except(['a']));
    }

    public function testItCanForgetAKey()
    {
        $this->context->add('key', 'value');
        $this->assertTrue($this->context->has('key'));

        $this->context->forget('key');
        $this->assertFalse($this->context->has('key'));
    }

    public function testItCanForgetMultipleKeys()
    {
        $this->context->add(['key1' => 'val1', 'key2' => 'val2', 'key3' => 'val3']);

        $this->context->forget(['key1', 'key2']);

        $this->assertFalse($this->context->has('key1'));
        $this->assertFalse($this->context->has('key2'));
        $this->assertTrue($this->context->has('key3'));
    }

    public function testItCanPullAValue()
    {
        $this->context->add('key', 'value');

        $this->assertSame('value', $this->context->pull('key'));
        $this->assertFalse($this->context->has('key'));
    }

    public function testItSilentlyIgnoresUnsetValues()
    {
        $this->assertNull($this->context->get('nonexistent'));
        $this->assertFalse($this->context->has('nonexistent'));
    }

    public function testGetReturnsDefaultForMissingKey()
    {
        $this->assertSame('fallback', $this->context->get('missing', 'fallback'));
    }

    public function testItIsSimpleKeyValueSystem()
    {
        $this->context->add('parent.child', 5);

        $this->assertNull($this->context->get('parent'));
        $this->assertSame(5, $this->context->get('parent.child'));
    }

    public function testItCanRememberAValue()
    {
        $callCount = 0;

        $result = $this->context->remember('key', function () use (&$callCount) {
            ++$callCount;
            return 'computed';
        });

        $this->assertSame('computed', $result);
        $this->assertSame(1, $callCount);

        $result = $this->context->remember('key', function () use (&$callCount) {
            ++$callCount;
            return 'recomputed';
        });

        $this->assertSame('computed', $result);
        $this->assertSame(1, $callCount);
    }

    public function testItCanRememberANonClosureValue()
    {
        $result = $this->context->remember('key', 42);
        $this->assertSame(42, $result);

        $result = $this->context->remember('key', 99);
        $this->assertSame(42, $result);
    }

    // =========================================================================
    // Stack operations
    // =========================================================================

    public function testItCanPushToList()
    {
        $this->context->push('breadcrumbs', 'foo');
        $this->context->push('breadcrumbs', 'bar', 'baz');

        $this->assertSame(['foo', 'bar', 'baz'], $this->context->get('breadcrumbs'));
    }

    public function testItThrowsWhenPushingToNonArray()
    {
        $this->context->add('key', 'string');

        $this->expectException(RuntimeException::class);
        $this->context->push('key', 'val');
    }

    public function testItThrowsWhenPushingToNonListArray()
    {
        $this->context->add('key', ['foo' => 'bar']);

        $this->expectException(RuntimeException::class);
        $this->context->push('key', 'val');
    }

    public function testItCanPopFromList()
    {
        $this->context->push('key', 'a', 'b');

        $this->assertSame('b', $this->context->pop('key'));
        $this->assertSame('a', $this->context->pop('key'));
    }

    public function testItThrowsWhenPoppingFromEmptyList()
    {
        $this->context->push('key', 'a');
        $this->context->pop('key');

        $this->expectException(RuntimeException::class);
        $this->context->pop('key');
    }

    public function testItThrowsWhenPoppingFromNonListArray()
    {
        $this->context->add('key', ['foo' => 'bar']);

        $this->expectException(RuntimeException::class);
        $this->context->pop('key');
    }

    public function testItCanCheckIfValueIsInStack()
    {
        $this->context->push('key', 'a', 'b', 'c');

        $this->assertTrue($this->context->stackContains('key', 'b'));
        $this->assertFalse($this->context->stackContains('key', 'z'));
    }

    public function testItCanCheckIfValueIsInStackWithClosure()
    {
        $this->context->push('key', 1, 2, 3);

        $this->assertTrue($this->context->stackContains('key', fn ($value) => $value === 2));
        $this->assertFalse($this->context->stackContains('key', fn ($value) => $value === 99));
    }

    // =========================================================================
    // Counter operations
    // =========================================================================

    public function testItCanIncrementACounter()
    {
        $this->context->increment('foo');
        $this->assertSame(1, $this->context->get('foo'));

        $this->context->increment('foo');
        $this->assertSame(2, $this->context->get('foo'));
    }

    public function testItCanIncrementWithCustomAmount()
    {
        $this->context->increment('foo', 5);
        $this->assertSame(5, $this->context->get('foo'));
    }

    public function testItCanDecrementACounter()
    {
        $this->context->increment('foo');
        $this->context->decrement('foo');
        $this->assertSame(0, $this->context->get('foo'));
    }

    public function testItCanDecrementWithCustomAmount()
    {
        $this->context->increment('foo', 10);
        $this->context->decrement('foo', 3);
        $this->assertSame(7, $this->context->get('foo'));
    }

    // =========================================================================
    // Hidden data operations
    // =========================================================================

    public function testItCanAddAndGetHiddenValues()
    {
        $this->context->addHidden('secret', 'data');

        $this->assertSame('data', $this->context->getHidden('secret'));
        $this->assertNull($this->context->get('secret'));
    }

    public function testItCanAddHiddenValuesWhenNotAlreadyPresent()
    {
        $this->context->addHiddenIf('key', 'first');
        $this->assertSame('first', $this->context->getHidden('key'));

        $this->context->addHiddenIf('key', 'second');
        $this->assertSame('first', $this->context->getHidden('key'));
    }

    public function testItCanCheckIfHiddenKeyExists()
    {
        $this->assertFalse($this->context->hasHidden('key'));
        $this->assertTrue($this->context->missingHidden('key'));

        $this->context->addHidden('key', 'value');

        $this->assertTrue($this->context->hasHidden('key'));
        $this->assertFalse($this->context->missingHidden('key'));
    }

    public function testItCanGetAllHiddenValues()
    {
        $this->context->addHidden(['a' => 1, 'b' => 2]);

        $this->assertSame(['a' => 1, 'b' => 2], $this->context->allHidden());
    }

    public function testItCanGetHiddenSubset()
    {
        $this->context->addHidden(['a' => 1, 'b' => 2, 'c' => 3]);

        $this->assertSame(['a' => 1, 'c' => 3], $this->context->onlyHidden(['a', 'c']));
        $this->assertSame(['b' => 2, 'c' => 3], $this->context->exceptHidden(['a']));
    }

    public function testItCanForgetHiddenKey()
    {
        $this->context->addHidden('key', 'value');
        $this->context->forgetHidden('key');

        $this->assertFalse($this->context->hasHidden('key'));
    }

    public function testItCanPullHiddenValue()
    {
        $this->context->addHidden('key', 'value');

        $this->assertSame('value', $this->context->pullHidden('key'));
        $this->assertFalse($this->context->hasHidden('key'));
    }

    public function testItCanRememberHiddenValue()
    {
        $result = $this->context->rememberHidden('key', fn () => 'secret');
        $this->assertSame('secret', $result);

        $result = $this->context->rememberHidden('key', fn () => 'other');
        $this->assertSame('secret', $result);
    }

    public function testItCanPushAndPopHiddenStack()
    {
        $this->context->pushHidden('key', 'a', 'b');

        $this->assertSame('b', $this->context->popHidden('key'));
        $this->assertSame('a', $this->context->popHidden('key'));
    }

    public function testItThrowsWhenPushingToNonListHidden()
    {
        $this->context->addHidden('key', ['foo' => 'bar']);

        $this->expectException(RuntimeException::class);
        $this->context->pushHidden('key', 'val');
    }

    public function testItThrowsWhenPoppingFromEmptyHiddenList()
    {
        $this->context->pushHidden('key', 'a');
        $this->context->popHidden('key');

        $this->expectException(RuntimeException::class);
        $this->context->popHidden('key');
    }

    public function testItCanCheckHiddenStackContains()
    {
        $this->context->pushHidden('key', 'a', 'b', 'c');

        $this->assertTrue($this->context->hiddenStackContains('key', 'b'));
        $this->assertFalse($this->context->hiddenStackContains('key', 'z'));
    }

    public function testItCanCheckHiddenStackContainsWithClosure()
    {
        $this->context->pushHidden('key', 1, 2, 3);

        $this->assertTrue($this->context->hiddenStackContains('key', fn ($value) => $value === 2));
        $this->assertFalse($this->context->hiddenStackContains('key', fn ($value) => $value === 99));
    }

    public function testItCannotCheckIfHiddenValueIsInNonHiddenContextStack()
    {
        $this->context->pushHidden('key', 'a', 'b');

        $this->assertFalse($this->context->stackContains('key', 'a'));
    }

    public function testHiddenDataIsSeparateFromVisibleData()
    {
        $this->context->add('key', 'visible');
        $this->context->addHidden('key', 'hidden');

        $this->assertSame('visible', $this->context->get('key'));
        $this->assertSame('hidden', $this->context->getHidden('key'));
    }

    // =========================================================================
    // Scope
    // =========================================================================

    public function testScopeAddsTemporaryContextAndRestores()
    {
        $this->context->add('existing', 'original');

        $result = $this->context->scope(function () {
            $this->assertSame('scoped', $this->context->get('temp'));
            $this->assertSame('original', $this->context->get('existing'));
            return 'return-value';
        }, ['temp' => 'scoped']);

        $this->assertSame('return-value', $result);
        $this->assertFalse($this->context->has('temp'));
        $this->assertSame('original', $this->context->get('existing'));
    }

    public function testScopeRestoresOnException()
    {
        $this->context->add('existing', 'original');

        try {
            $this->context->scope(function () {
                throw new RuntimeException('test');
            }, ['temp' => 'scoped']);
        } catch (RuntimeException) {
            // expected
        }

        $this->assertFalse($this->context->has('temp'));
        $this->assertSame('original', $this->context->get('existing'));
    }

    public function testScopeAddsHiddenContextAndRestores()
    {
        $this->context->addHidden('existing', 'original');

        $this->context->scope(function () {
            $this->assertSame('scoped', $this->context->getHidden('temp'));
            $this->assertSame('original', $this->context->getHidden('existing'));
        }, [], ['temp' => 'scoped']);

        $this->assertFalse($this->context->hasHidden('temp'));
        $this->assertSame('original', $this->context->getHidden('existing'));
    }

    // =========================================================================
    // Lifecycle
    // =========================================================================

    public function testIsEmptyWhenNoData()
    {
        $this->assertTrue($this->context->isEmpty());

        $this->context->add('key', 'value');
        $this->assertFalse($this->context->isEmpty());

        $this->context->flush();
        $this->assertTrue($this->context->isEmpty());
    }

    public function testFlushClearsAllData()
    {
        $this->context->add('key', 'value');
        $this->context->addHidden('secret', 'data');

        $this->context->flush();

        $this->assertSame([], $this->context->all());
        $this->assertSame([], $this->context->allHidden());
    }

    // =========================================================================
    // Serialization (dehydrate / hydrate)
    // =========================================================================

    public function testItCanSerializeAndDeserializeValues()
    {
        $this->context->add([
            'string' => 'hello',
            'bool' => true,
            'int' => 42,
            'float' => 3.14,
            'null' => null,
            'array' => [1, 2, 3],
            'enum' => Suit::Clubs,
            'backed_enum' => StringBackedSuit::Clubs,
        ]);

        $this->events->shouldReceive('dispatch')
            ->once()
            ->with(m::type(ContextDehydrating::class));

        $payload = $this->context->dehydrate();
        $this->assertNotNull($payload);
        $this->assertArrayHasKey('data', $payload);
        $this->assertArrayHasKey('hidden', $payload);

        // Hydrate into a fresh context
        $fresh = new Repository($this->events);
        $this->events->shouldReceive('dispatch')
            ->once()
            ->with(m::type(ContextHydrated::class));

        $fresh->hydrate($payload);

        $this->assertSame('hello', $fresh->get('string'));
        $this->assertTrue($fresh->get('bool'));
        $this->assertSame(42, $fresh->get('int'));
        $this->assertSame(3.14, $fresh->get('float'));
        $this->assertNull($fresh->get('null'));
        $this->assertSame([1, 2, 3], $fresh->get('array'));
        $this->assertSame(Suit::Clubs, $fresh->get('enum'));
        $this->assertSame(StringBackedSuit::Clubs, $fresh->get('backed_enum'));
    }

    public function testDehydrateReturnsNullWhenEmpty()
    {
        $this->assertNull($this->context->dehydrate());
    }

    public function testDehydrateIncludesHiddenData()
    {
        $this->context->addHidden('secret', 'token');
        $this->events->shouldReceive('dispatch')
            ->once()
            ->with(m::type(ContextDehydrating::class));

        $payload = $this->context->dehydrate();

        $this->assertNotNull($payload);
        $this->assertArrayHasKey('hidden', $payload);
        $this->assertArrayHasKey('secret', $payload['hidden']);
    }

    public function testHydratingNullTriggersHydratedEvent()
    {
        $this->events->shouldReceive('dispatch')
            ->once()
            ->with(m::type(ContextHydrated::class));

        $this->context->hydrate(null);
    }

    public function testHydrateFlushesExistingDataBeforeRestoring()
    {
        $this->context->add('old', 'data');

        $this->events->shouldReceive('dispatch')
            ->once()
            ->with(m::type(ContextDehydrating::class));
        $this->events->shouldReceive('dispatch')
            ->once()
            ->with(m::type(ContextHydrated::class));

        $other = new Repository($this->events);
        $other->add('new', 'data');
        $payload = $other->dehydrate();

        $this->context->hydrate($payload);

        $this->assertFalse($this->context->has('old'));
        $this->assertSame('data', $this->context->get('new'));
    }

    public function testDehydratingCallbackCanModifyWithoutAffectingOriginal()
    {
        $this->context->add('key', 'original');

        $this->events->shouldReceive('dispatch')
            ->once()
            ->with(m::on(function (ContextDehydrating $event) {
                $event->context->add('key', 'modified');
                $event->context->add('extra', 'added');
                return true;
            }));

        $payload = $this->context->dehydrate();

        // Original is untouched
        $this->assertSame('original', $this->context->get('key'));
        $this->assertFalse($this->context->has('extra'));

        // Payload has the modifications
        $this->assertSame('modified', unserialize($payload['data']['key']));
        $this->assertSame('added', unserialize($payload['data']['extra']));
    }

    public function testHydratedCallbackFiresAfterHydration()
    {
        $callbackContext = null;

        $this->events->shouldReceive('dispatch')
            ->once()
            ->with(m::type(ContextDehydrating::class));
        $this->events->shouldReceive('dispatch')
            ->once()
            ->with(m::on(function (ContextHydrated $event) use (&$callbackContext) {
                $callbackContext = $event->context;
                return true;
            }));

        $other = new Repository($this->events);
        $other->add('key', 'value');
        $payload = $other->dehydrate();

        $this->context->hydrate($payload);

        $this->assertNotNull($callbackContext);
        $this->assertSame('value', $callbackContext->get('key'));
    }

    // =========================================================================
    // Events
    // =========================================================================

    public function testContextDehydratingEventIsDispatched()
    {
        $this->context->add('key', 'value');

        $this->events->shouldReceive('dispatch')
            ->once()
            ->with(m::type(ContextDehydrating::class));

        $this->context->dehydrate();
    }

    public function testContextHydratedEventIsDispatched()
    {
        $this->events->shouldReceive('dispatch')
            ->once()
            ->with(m::type(ContextHydrated::class));

        $this->context->hydrate(null);
    }

    public function testContextDehydratingEventIsSkippedWhenNoListenersAreRegistered()
    {
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->once()->with(ContextDehydrating::class)->andReturn(false);
        $events->shouldNotReceive('dispatch');

        $context = new Repository($events);
        $context->add('key', 'value');

        $payload = $context->dehydrate();

        $this->assertNotNull($payload);
        $this->assertArrayHasKey('key', $payload['data']);
    }

    public function testContextHydratedEventIsSkippedWhenNoListenersAreRegistered()
    {
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->once()->with(ContextHydrated::class)->andReturn(false);
        $events->shouldNotReceive('dispatch');

        $context = new Repository($events);
        $context->hydrate([
            'data' => ['key' => serialize('value')],
        ]);

        $this->assertSame('value', $context->get('key'));
    }

    public function testDehydratingEventReceivesCloneNotOriginal()
    {
        $this->context->add('key', 'value');

        $eventContext = null;
        $this->events->shouldReceive('dispatch')
            ->once()
            ->with(m::on(function (ContextDehydrating $event) use (&$eventContext) {
                $eventContext = $event->context;
                return true;
            }));

        $this->context->dehydrate();

        $this->assertNotSame($this->context, $eventContext);
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
