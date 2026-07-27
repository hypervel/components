<?php

declare(strict_types=1);

namespace Hypervel\Tests\Session;

use Hypervel\Container\Container;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Auth\Factory as AuthFactory;
use Hypervel\Http\Request;
use Hypervel\Session\CookieSessionHandler;
use Hypervel\Session\Store;
use Hypervel\Support\MessageBag;
use Hypervel\Support\Str;
use Hypervel\Support\Uri;
use Hypervel\Support\ViewErrorBag;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use JsonException;
use Mockery as m;
use RuntimeException;
use SessionHandlerInterface;
use UnitEnum;

use function Hypervel\Coroutine\parallel;

class SessionStoreTest extends TestCase
{
    public function testSessionIsLoadedFromHandler(): void
    {
        $session = $this->getSession();
        $session->getHandler()->shouldReceive('read')->once()->with($this->getSessionId())->andReturn(serialize(['foo' => 'bar', 'bagged' => ['name' => 'taylor'], '123' => 'bax']));
        $session->start();

        $this->assertSame('bar', $session->get('foo'));
        $this->assertSame('bax', $session->get('123'));
        $this->assertSame('baz', $session->get('bar', 'baz'));
        $this->assertTrue($session->has('foo'));
        $this->assertTrue($session->has('123'));
        $this->assertFalse($session->has('bar'));
        $this->assertTrue($session->isStarted());

        $session->put('baz', 'boom');
        $this->assertTrue($session->has('baz'));
    }

    public function testSessionMigration(): void
    {
        $session = $this->getSession();
        $oldId = $session->getId();
        $session->getHandler()->shouldReceive('destroy')->never();
        $this->assertTrue($session->migrate());
        $this->assertNotEquals($oldId, $session->getId());

        $session = $this->getSession();
        $oldId = $session->getId();
        $session->getHandler()->shouldReceive('destroy')->once()->with($oldId);
        $this->assertTrue($session->migrate(true));
        $this->assertNotEquals($oldId, $session->getId());
    }

    public function testSessionRegeneration(): void
    {
        $session = $this->getSession();
        $oldId = $session->getId();
        $session->getHandler()->shouldReceive('destroy')->never();
        $this->assertTrue($session->regenerate());
        $this->assertNotEquals($oldId, $session->getId());
    }

    public function testCantSetInvalidId(): void
    {
        $session = $this->getSession();
        $this->assertTrue($session->isValidId($session->getId()));

        $session->setId(null);
        $this->assertNotNull($session->getId());
        $this->assertTrue($session->isValidId($session->getId()));

        $session->setId('wrong');
        $this->assertNotSame('wrong', $session->getId());
    }

    public function testStoresUseIndependentCoroutineState(): void
    {
        $handler = m::mock(SessionHandlerInterface::class);
        $handler->shouldReceive('read')->once()->andReturn(serialize([]));

        $first = new Store('first', $handler, str_repeat('a', 40));
        $first->start();
        $first->put('name', 'first');

        $second = new Store('second', $handler, str_repeat('b', 40));
        $second->put('name', 'second');

        $this->assertSame(str_repeat('a', 40), $first->getId());
        $this->assertSame(str_repeat('b', 40), $second->getId());
        $this->assertSame('first', $first->get('name'));
        $this->assertSame('second', $second->get('name'));
        $this->assertTrue($first->isStarted());
        $this->assertFalse($second->isStarted());
    }

    public function testConstructionClearsStaleObjectSpecificCoroutineState(): void
    {
        $handler = m::mock(SessionHandlerInterface::class);

        $session = new class('name', $handler, str_repeat('b', 40)) extends Store {
            public function __construct(string $name, SessionHandlerInterface $handler, ?string $id = null)
            {
                // Model stale slots from a released Store whose object ID PHP reused.
                $suffix = (string) spl_object_id($this);

                CoroutineContext::set(self::STARTED_CONTEXT_KEY_PREFIX . $suffix, true);
                CoroutineContext::set(self::ATTRIBUTES_CONTEXT_KEY_PREFIX . $suffix, ['name' => 'stale']);
                CoroutineContext::set(self::ID_CONTEXT_KEY_PREFIX . $suffix, str_repeat('a', 40));

                parent::__construct($name, $handler, $id);
            }
        };

        $this->assertSame(str_repeat('b', 40), $session->getId());
        $this->assertSame([], $session->all());
        $this->assertFalse($session->isStarted());
    }

    public function testStoreLazilyCreatesAnIdInAFreshCoroutine(): void
    {
        $session = new Store('name', m::mock(SessionHandlerInterface::class), str_repeat('a', 40));

        [$id] = parallel([
            fn (): string => $session->getId(),
        ]);

        $this->assertSame(40, strlen($id));
        $this->assertTrue($session->isValidId($id));
        $this->assertNotSame(str_repeat('a', 40), $id);
    }

    public function testInvalidSerializationFailsBeforeWritingContext(): void
    {
        $context = CoroutineContext::captureFrom();

        try {
            new Store('name', m::mock(SessionHandlerInterface::class), serialization: 'yaml');

            $this->fail('Expected invalid session serialization to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'Session serialization [yaml] is not supported. Supported: "json", "php".',
                $exception->getMessage(),
            );
        }

        $this->assertSame($context, CoroutineContext::captureFrom());
    }

    public function testSessionInvalidate(): void
    {
        $session = $this->getSession();
        $oldId = $session->getId();

        $session->put('foo', 'bar');
        $this->assertGreaterThan(0, count($session->all()));

        $session->flash('name', 'Taylor');
        $this->assertTrue($session->has('name'));

        $session->getHandler()->shouldReceive('destroy')->once()->with($oldId);
        $this->assertTrue($session->invalidate());

        $this->assertFalse($session->has('name'));
        $this->assertNotEquals($oldId, $session->getId());
        $this->assertCount(0, $session->all());
    }

    public function testBrandNewSessionIsProperlySaved(): void
    {
        $session = $this->getSession();
        $session->getHandler()->shouldReceive('read')->once()->andReturn(serialize([]));
        $session->start();
        $session->put('foo', 'bar');
        $session->flash('baz', 'boom');
        $session->now('qux', 'norf');
        $session->getHandler()->shouldReceive('write')->once()->with(
            $this->getSessionId(),
            serialize([
                '_token' => $session->token(),
                'foo' => 'bar',
                'baz' => 'boom',
                '_flash' => [
                    'new' => [],
                    'old' => ['baz'],
                ],
            ])
        )->andReturnTrue();
        $session->save();

        $this->assertFalse($session->isStarted());
    }

    public function testSessionIsProperlyUpdated(): void
    {
        $session = $this->getSession();
        $session->getHandler()->shouldReceive('read')->once()->andReturn(serialize([
            '_token' => Str::random(40),
            'foo' => 'bar',
            'baz' => 'boom',
            '_flash' => [
                'new' => [],
                'old' => ['baz'],
            ],
        ]));
        $session->start();

        $session->getHandler()->shouldReceive('write')->once()->with(
            $this->getSessionId(),
            serialize([
                '_token' => $session->token(),
                'foo' => 'bar',
                '_flash' => [
                    'new' => [],
                    'old' => [],
                ],
            ])
        )->andReturnTrue();

        $session->save();

        $this->assertFalse($session->isStarted());
    }

    public function testSessionIsReSavedWhenNothingHasChanged(): void
    {
        $session = $this->getSession();
        $session->getHandler()->shouldReceive('read')->once()->andReturn(serialize([
            '_token' => Str::random(40),
            'foo' => 'bar',
            'baz' => 'boom',
            '_flash' => [
                'new' => [],
                'old' => [],
            ],
        ]));
        $session->start();

        $session->getHandler()->shouldReceive('write')->once()->with(
            $this->getSessionId(),
            serialize([
                '_token' => $session->token(),
                'foo' => 'bar',
                'baz' => 'boom',
                '_flash' => [
                    'new' => [],
                    'old' => [],
                ],
            ])
        )->andReturnTrue();

        $session->save();

        $this->assertFalse($session->isStarted());
    }

    public function testSessionIsReSavedWhenNothingHasChangedExceptSessionId(): void
    {
        $session = $this->getSession();
        $oldId = $session->getId();
        $token = Str::random(40);
        $session->getHandler()->shouldReceive('read')->once()->with($oldId)->andReturn(serialize([
            '_token' => $token,
            'foo' => 'bar',
            'baz' => 'boom',
            '_flash' => [
                'new' => [],
                'old' => [],
            ],
        ]));
        $session->start();

        $oldId = $session->getId();
        $session->migrate();
        $newId = $session->getId();

        $this->assertNotEquals($newId, $oldId);

        $session->getHandler()->shouldReceive('write')->once()->with(
            $newId,
            serialize([
                '_token' => $token,
                'foo' => 'bar',
                'baz' => 'boom',
                '_flash' => [
                    'new' => [],
                    'old' => [],
                ],
            ])
        )->andReturnTrue();

        $session->save();

        $this->assertFalse($session->isStarted());
    }

    public function testFailedSaveDoesNotPublishAgedFlashDataBeforeRetry(): void
    {
        $handler = m::mock(SessionHandlerInterface::class);
        $handler->shouldReceive('read')->once()->andReturn(serialize([]));

        $attempts = 0;
        $payloads = [];
        $handler->shouldReceive('write')->twice()->andReturnUsing(
            function (string $sessionId, string $data) use (&$attempts, &$payloads): bool {
                $payloads[] = unserialize($data);

                if (++$attempts === 1) {
                    throw new RuntimeException('Unable to persist the session.');
                }

                return true;
            }
        );

        $session = new Store('name', $handler, $this->getSessionId());
        $session->start();
        $session->flash('status', 'saved');

        try {
            $session->save();

            $this->fail('Expected the first session write to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to persist the session.', $exception->getMessage());
        }

        $this->assertTrue($session->isStarted());
        $this->assertSame(['status'], $session->get('_flash.new'));
        $this->assertSame([], $session->get('_flash.old'));

        $session->save();

        $this->assertFalse($session->isStarted());
        $this->assertSame([], $session->get('_flash.new'));
        $this->assertSame(['status'], $session->get('_flash.old'));
        $this->assertSame($payloads[0], $payloads[1]);
    }

    public function testOldInputFlashing(): void
    {
        $session = $this->getSession();
        $session->put('boom', 'baz');
        $session->flashInput(['foo' => 'bar', 'bar' => 0, 'name' => null]);

        $this->assertTrue($session->hasOldInput('foo'));
        $this->assertSame('bar', $session->getOldInput('foo'));
        $this->assertEquals(0, $session->getOldInput('bar'));
        $this->assertFalse($session->hasOldInput('boom'));

        $session->ageFlashData();

        $this->assertTrue($session->hasOldInput('foo'));
        $this->assertSame('bar', $session->getOldInput('foo'));
        $this->assertEquals(0, $session->getOldInput('bar'));
        $this->assertFalse($session->hasOldInput('boom'));

        $this->assertSame('default', $session->getOldInput('input', 'default'));
        $this->assertNull($session->getOldInput('name', 'default'));
    }

    public function testDataFlashing(): void
    {
        $session = $this->getSession();
        $session->flash('foo', 'bar');
        $session->flash('bar', 0);
        $session->flash('baz');

        $this->assertTrue($session->has('foo'));
        $this->assertSame('bar', $session->get('foo'));
        $this->assertEquals(0, $session->get('bar'));
        $this->assertTrue($session->get('baz'));

        $session->ageFlashData();

        $this->assertTrue($session->has('foo'));
        $this->assertSame('bar', $session->get('foo'));
        $this->assertEquals(0, $session->get('bar'));

        $session->ageFlashData();

        $this->assertFalse($session->has('foo'));
        $this->assertNull($session->get('foo'));
    }

    public function testDataFlashingNow(): void
    {
        $session = $this->getSession();
        $session->now('foo', 'bar');
        $session->now('bar', 0);

        $this->assertTrue($session->has('foo'));
        $this->assertSame('bar', $session->get('foo'));
        $this->assertEquals(0, $session->get('bar'));

        $session->ageFlashData();

        $this->assertFalse($session->has('foo'));
        $this->assertNull($session->get('foo'));
    }

    public function testDataMergeNewFlashes(): void
    {
        $session = $this->getSession();
        $session->flash('foo', 'bar');
        $session->put('fu', 'baz');
        $session->put('_flash.old', ['qu']);
        $this->assertNotFalse(array_search('foo', $session->get('_flash.new')));
        $this->assertFalse(array_search('fu', $session->get('_flash.new')));
        $session->keep(['fu', 'qu']);
        $this->assertNotFalse(array_search('foo', $session->get('_flash.new')));
        $this->assertNotFalse(array_search('fu', $session->get('_flash.new')));
        $this->assertNotFalse(array_search('qu', $session->get('_flash.new')));
        $this->assertFalse(array_search('qu', $session->get('_flash.old')));
    }

    public function testReflash(): void
    {
        $session = $this->getSession();
        $session->flash('foo', 'bar');
        $session->put('_flash.old', ['foo']);
        $session->reflash();
        $this->assertNotFalse(array_search('foo', $session->get('_flash.new')));
        $this->assertFalse(array_search('foo', $session->get('_flash.old')));
    }

    public function testReflashWithNow(): void
    {
        $session = $this->getSession();
        $session->now('foo', 'bar');
        $session->reflash();
        $this->assertNotFalse(array_search('foo', $session->get('_flash.new')));
        $this->assertFalse(array_search('foo', $session->get('_flash.old')));
    }

    public function testOnly(): void
    {
        $session = $this->getSession();
        $session->put('foo', 'bar');
        $session->put('qu', 'ux');
        $this->assertEquals(['foo' => 'bar', 'qu' => 'ux'], $session->all());
        $this->assertEquals(['qu' => 'ux'], $session->only(['qu']));
    }

    public function testExcept(): void
    {
        $session = $this->getSession();
        $session->put('foo', 'bar');
        $session->put('bar', 'baz');
        $session->put('qu', 'ux');

        $this->assertEquals(['foo' => 'bar', 'qu' => 'ux', 'bar' => 'baz'], $session->all());
        $this->assertEquals(['bar' => 'baz', 'qu' => 'ux'], $session->except(['foo']));
    }

    public function testReplace(): void
    {
        $session = $this->getSession();
        $session->put('foo', 'bar');
        $session->put('qu', 'ux');
        $session->replace(['foo' => 'baz']);
        $this->assertSame('baz', $session->get('foo'));
        $this->assertSame('ux', $session->get('qu'));
    }

    public function testRemove(): void
    {
        $session = $this->getSession();
        $session->put('foo', 'bar');
        $pulled = $session->remove('foo');
        $this->assertFalse($session->has('foo'));
        $this->assertSame('bar', $pulled);
    }

    public function testClear(): void
    {
        $session = $this->getSession();
        $session->put('foo', 'bar');

        $session->flush();
        $this->assertFalse($session->has('foo'));

        $session->put('foo', 'bar');

        $session->flush();
        $this->assertFalse($session->has('foo'));
    }

    public function testIncrement(): void
    {
        $session = $this->getSession();

        $session->put('foo', 5);
        $foo = $session->increment('foo');
        $this->assertEquals(6, $foo);
        $this->assertEquals(6, $session->get('foo'));

        $foo = $session->increment('foo', 4);
        $this->assertEquals(10, $foo);
        $this->assertEquals(10, $session->get('foo'));

        $session->increment('bar');
        $this->assertEquals(1, $session->get('bar'));
    }

    public function testDecrement(): void
    {
        $session = $this->getSession();

        $session->put('foo', 5);
        $foo = $session->decrement('foo');
        $this->assertEquals(4, $foo);
        $this->assertEquals(4, $session->get('foo'));

        $foo = $session->decrement('foo', 4);
        $this->assertEquals(0, $foo);
        $this->assertEquals(0, $session->get('foo'));

        $session->decrement('bar');
        $this->assertEquals(-1, $session->get('bar'));
    }

    public function testHasOldInputWithoutKey(): void
    {
        $session = $this->getSession();
        $session->flash('boom', 'baz');
        $this->assertFalse($session->hasOldInput());

        $session->flashInput(['foo' => 'bar']);
        $this->assertTrue($session->hasOldInput());
    }

    public function testHandlerNeedsRequest(): void
    {
        $session = $this->getSession();
        $this->assertFalse($session->handlerNeedsRequest());
        $session->getHandler()->shouldReceive('setRequest')->never();

        $handler = m::mock(CookieSessionHandler::class);
        $session = new Store('test', $handler);
        $this->assertTrue($session->handlerNeedsRequest());
        $handler->shouldReceive('setRequest')->once();
        $session->setRequestOnHandler(new Request);
    }

    public function testToken(): void
    {
        $session = $this->getSession();
        $this->assertNull($session->token());

        $session->regenerate();
        $this->assertEquals($session->token(), $session->token());
    }

    public function testRegenerateToken(): void
    {
        $session = $this->getSession();
        $token = $session->token();
        $session->regenerateToken();
        $this->assertNotEquals($token, $session->token());
    }

    public function testName(): void
    {
        $session = $this->getSession();
        $this->assertEquals($session->getName(), $this->getSessionName());
        $session->setName('foo');
        $this->assertSame('foo', $session->getName());
    }

    public function testForget(): void
    {
        $session = $this->getSession();
        $session->put('foo', 'bar');
        $this->assertTrue($session->has('foo'));
        $session->forget('foo');
        $this->assertFalse($session->has('foo'));

        $session->put('foo', 'bar');
        $session->put('bar', 'baz');
        $session->forget(['foo', 'bar']);
        $this->assertFalse($session->has('foo'));
        $this->assertFalse($session->has('bar'));
    }

    public function testSetPreviousUrl(): void
    {
        $session = $this->getSession();
        $session->setPreviousUrl('https://example.com/foo/bar');

        $this->assertTrue($session->has('_previous.url'));
        $this->assertSame('https://example.com/foo/bar', $session->get('_previous.url'));

        $url = $session->previousUrl();
        $this->assertSame('https://example.com/foo/bar', $url);
    }

    public function testPasswordConfirmed(): void
    {
        $session = $this->getSession();
        $this->assertFalse($session->has('auth.password_confirmed_at_web'));
        $session->passwordConfirmed('web');
        $this->assertTrue($session->has('auth.password_confirmed_at_web'));
    }

    public function testPasswordConfirmedResolvesCurrentGuardWhenNoneGiven(): void
    {
        $previousContainer = Container::getInstance();
        $container = Container::setInstance(new Container);

        try {
            $auth = m::mock(AuthFactory::class);
            $auth->shouldReceive('getDefaultDriver')->once()->andReturn('admin');
            $container->instance(AuthFactory::class, $auth);

            $session = $this->getSession();
            $session->passwordConfirmed();

            $this->assertTrue($session->has('auth.password_confirmed_at_admin'));
        } finally {
            Container::setInstance($previousContainer);
        }
    }

    public function testKeyPush(): void
    {
        $session = $this->getSession();
        $session->put('language', ['PHP' => ['Laravel']]);
        $session->push('language.PHP', 'Symfony');

        $this->assertEquals(['PHP' => ['Laravel', 'Symfony']], $session->get('language'));
    }

    public function testKeyPull(): void
    {
        $session = $this->getSession();
        $session->put('name', 'Taylor');

        $this->assertSame('Taylor', $session->pull('name'));
        $this->assertSame('Taylor Otwell', $session->pull('name', 'Taylor Otwell'));
        $this->assertNull($session->pull('name'));
    }

    public function testKeyHas(): void
    {
        $session = $this->getSession();
        $session->put('first_name', 'Mehdi');
        $session->put('last_name', 'Rajabi');

        $this->assertTrue($session->has('first_name'));
        $this->assertTrue($session->has('last_name'));
        $this->assertTrue($session->has('first_name', 'last_name'));
        $this->assertTrue($session->has(['first_name', 'last_name']));

        $this->assertFalse($session->has('first_name', 'foo'));
        $this->assertFalse($session->has('foo', 'bar'));
    }

    public function testKeyHasAny(): void
    {
        $session = $this->getSession();
        $session->put('first_name', 'Mahmoud');
        $session->put('last_name', 'Ramadan');

        $this->assertTrue($session->hasAny('first_name'));
        $this->assertTrue($session->hasAny('first_name', 'last_name'));
        $this->assertTrue($session->hasAny(['first_name', 'last_name']));
        $this->assertTrue($session->hasAny(['first_name', 'middle_name']));

        $this->assertFalse($session->hasAny('middle_name'));
        $this->assertFalse($session->hasAny('foo', 'bar'));
        $this->assertFalse($session->hasAny(['foo', 'bar']));
    }

    public function testHasAnyStopsAfterTheFirstPresentKey(): void
    {
        $session = new class('name', m::mock(SessionHandlerInterface::class), $this->getSessionId()) extends Store {
            public int $getCalls = 0;

            public function get(UnitEnum|string $key, mixed $default = null): mixed
            {
                ++$this->getCalls;

                if ($key === 'first') {
                    return 'value';
                }

                throw new RuntimeException('The second key should not be read.');
            }
        };

        $this->assertTrue($session->hasAny(['first', 'second']));
        $this->assertSame(1, $session->getCalls);
    }

    public function testKeyExists(): void
    {
        $session = $this->getSession();
        $session->put('foo', 'bar');
        $this->assertTrue($session->exists('foo'));
        $session->put('baz', null);
        $session->put('hulk', ['one' => true]);
        $this->assertFalse($session->has('baz'));
        $this->assertTrue($session->exists('baz'));
        $this->assertFalse($session->exists('bogus'));
        $this->assertTrue($session->exists(['foo', 'baz']));
        $this->assertFalse($session->exists(['foo', 'baz', 'bogus']));
        $this->assertTrue($session->exists(['hulk.one']));
        $this->assertFalse($session->exists(['hulk.two']));
    }

    public function testKeyMissing(): void
    {
        $session = $this->getSession();
        $session->put('foo', 'bar');
        $this->assertFalse($session->missing('foo'));
        $session->put('baz', null);
        $session->put('hulk', ['one' => true]);
        $this->assertFalse($session->has('baz'));
        $this->assertFalse($session->missing('baz'));
        $this->assertTrue($session->missing('bogus'));
        $this->assertFalse($session->missing(['foo', 'baz']));
        $this->assertTrue($session->missing(['foo', 'baz', 'bogus']));
        $this->assertFalse($session->missing(['hulk.one']));
        $this->assertTrue($session->missing(['hulk.two']));
    }

    public function testBackedEnumKeyPut(): void
    {
        $session = $this->getSession();
        $session->put(SessionTestKey::User, 'Taylor');

        $this->assertSame('Taylor', $session->get('user'));
        $this->assertSame('Taylor', $session->get(SessionTestKey::User));
    }

    public function testBackedEnumKeyGet(): void
    {
        $session = $this->getSession();
        $session->put('user', 'Taylor');

        $this->assertSame('Taylor', $session->get(SessionTestKey::User));
        $this->assertSame('default', $session->get(SessionTestKey::Settings, 'default'));
    }

    public function testBackedEnumKeyHas(): void
    {
        $session = $this->getSession();
        $session->put(SessionTestKey::User, 'Taylor');
        $session->put(SessionTestKey::Settings, 'dark-mode');

        $this->assertTrue($session->has(SessionTestKey::User));
        $this->assertTrue($session->has(SessionTestKey::User, SessionTestKey::Settings));
        $this->assertTrue($session->has([SessionTestKey::User, SessionTestKey::Settings]));
        $this->assertFalse($session->has(SessionTestKey::Preference));
    }

    public function testBackedEnumKeyHasAny(): void
    {
        $session = $this->getSession();
        $session->put(SessionTestKey::User, 'Taylor');
        $session->put(SessionTestKey::Settings, 'dark-mode');

        $this->assertTrue($session->hasAny(SessionTestKey::User));
        $this->assertTrue($session->hasAny('user'));
        $this->assertTrue($session->hasAny(SessionTestKey::User, SessionTestKey::Preference, 'foo'));
        $this->assertTrue($session->hasAny([SessionTestKey::User, SessionTestKey::Preference, 'foo']));

        $this->assertFalse($session->hasAny(SessionTestKey::Preference));
        $this->assertFalse($session->hasAny('preference'));
        $this->assertFalse($session->hasAny(SessionTestKey::Preference, 'foo'));
        $this->assertFalse($session->hasAny([SessionTestKey::Preference, 'foo']));
    }

    public function testBackedEnumKeyExists(): void
    {
        $session = $this->getSession();
        $session->put(SessionTestKey::User, 'Taylor');
        $session->put(SessionTestKey::Settings, null);

        $this->assertTrue($session->exists(SessionTestKey::User));
        $this->assertTrue($session->exists(SessionTestKey::Settings));
        $this->assertFalse($session->exists(SessionTestKey::Preference));

        $this->assertTrue($session->exists('user'));
        $this->assertFalse($session->exists('preference'));
    }

    public function testBackedEnumKeyMissing(): void
    {
        $session = $this->getSession();
        $session->put(SessionTestKey::User, 'Taylor');
        $session->put(SessionTestKey::Settings, null);

        $this->assertFalse($session->missing(SessionTestKey::User));
        $this->assertFalse($session->missing(SessionTestKey::Settings));
        $this->assertTrue($session->missing(SessionTestKey::Preference));

        $this->assertFalse($session->missing('user'));
        $this->assertTrue($session->missing('preference'));
    }

    public function testBackedEnumKeyForget(): void
    {
        $session = $this->getSession();
        $session->put(SessionTestKey::User, 'Taylor');
        $this->assertTrue($session->has('user'));

        $session->forget(SessionTestKey::User);
        $this->assertFalse($session->has('user'));

        $session->put(SessionTestKey::User, 'Taylor');
        $session->put(SessionTestKey::Settings, 'dark-mode');
        $session->forget([SessionTestKey::User, SessionTestKey::Settings]);
        $this->assertFalse($session->has('user'));
        $this->assertFalse($session->has('settings'));
    }

    public function testBackedEnumKeyPull(): void
    {
        $session = $this->getSession();
        $session->put(SessionTestKey::User, 'Taylor');

        $this->assertSame('Taylor', $session->pull(SessionTestKey::User));
        $this->assertNull($session->pull(SessionTestKey::User));
        $this->assertSame('default', $session->pull(SessionTestKey::User, 'default'));
    }

    public function testBackedEnumKeyRemember(): void
    {
        $session = $this->getSession();

        $result = $session->remember(SessionTestKey::User, fn () => 'Taylor');

        $this->assertSame('Taylor', $result);
        $this->assertSame('Taylor', $session->get('user'));
        $this->assertSame('Taylor', $session->remember(SessionTestKey::User, fn () => 'Otwell'));
    }

    public function testBackedEnumKeyPush(): void
    {
        $session = $this->getSession();
        $session->put(SessionTestKey::User, ['Taylor']);
        $session->push(SessionTestKey::User, 'Otwell');

        $this->assertSame(['Taylor', 'Otwell'], $session->get('user'));
    }

    public function testBackedEnumKeyIncrement(): void
    {
        $session = $this->getSession();
        $session->put(SessionTestKey::User, 5);

        $this->assertSame(6, $session->increment(SessionTestKey::User));
        $this->assertSame(6, $session->get('user'));

        $this->assertSame(10, $session->increment(SessionTestKey::User, 4));
        $this->assertSame(10, $session->get('user'));
    }

    public function testBackedEnumKeyDecrement(): void
    {
        $session = $this->getSession();
        $session->put(SessionTestKey::User, 5);

        $this->assertSame(4, $session->decrement(SessionTestKey::User));
        $this->assertSame(4, $session->get('user'));
    }

    public function testBackedEnumKeyRemove(): void
    {
        $session = $this->getSession();
        $session->put(SessionTestKey::User, 'Taylor');

        $this->assertSame('Taylor', $session->remove(SessionTestKey::User));
        $this->assertFalse($session->has('user'));
    }

    public function testBackedEnumKeyFlash(): void
    {
        $session = $this->getSession();
        $session->flash(SessionTestKey::User, 'Taylor');
        $this->assertTrue($session->has(SessionTestKey::User));
    }

    public function testBackedEnumKeyNow(): void
    {
        $session = $this->getSession();
        $session->now(SessionTestKey::User, 'Taylor');
        $this->assertTrue($session->has(SessionTestKey::User));
    }

    public function testRememberMethodCallsPutAndReturnsDefault(): void
    {
        $session = $this->getSession();
        $session->getHandler()->shouldReceive('get')->andReturn(null);
        $result = $session->remember('foo', function () {
            return 'bar';
        });
        $this->assertSame('bar', $session->get('foo'));
        $this->assertSame('bar', $result);
    }

    public function testRememberMethodReturnsPreviousValueIfItAlreadySets(): void
    {
        $session = $this->getSession();
        $session->put('key', 'foo');
        $result = $session->remember('key', function () {
            return 'bar';
        });
        $this->assertSame('foo', $session->get('key'));
        $this->assertSame('foo', $result);
    }

    public function testValidationErrorsCanBeSerializedAsJson(): void
    {
        $session = $this->getSession('json');
        $session->getHandler()->shouldReceive('read')->once()->andReturn(json_encode([]));
        $session->start();
        $session->put('errors', $this->getErrorBag());

        $session->getHandler()->shouldReceive('write')->once()->with(
            $this->getSessionId(),
            json_encode([
                '_token' => $session->token(),
                'errors' => [
                    'default' => [
                        'format' => '<p>:message</p>',
                        'messages' => [
                            'first_name' => [
                                'Your first name is required',
                                'Your first name must be at least 1 character',
                            ],
                        ],
                    ],
                ],
                '_flash' => [
                    'old' => [],
                    'new' => [],
                ],
            ])
        )->andReturnTrue();
        $session->save();

        $this->assertFalse($session->isStarted());
    }

    public function testFailedJsonSaveKeepsLiveErrorBagAndFlashUntilRetry(): void
    {
        $handler = m::mock(SessionHandlerInterface::class);
        $handler->shouldReceive('read')->once()->andReturn(json_encode([]));

        $attempts = 0;
        $payloads = [];
        $handler->shouldReceive('write')->twice()->andReturnUsing(
            function (string $sessionId, string $data) use (&$attempts, &$payloads): bool {
                $payloads[] = json_decode($data, true, flags: JSON_THROW_ON_ERROR);

                if (++$attempts === 1) {
                    throw new RuntimeException('Unable to persist the session.');
                }

                return true;
            }
        );

        $session = new Store('name', $handler, $this->getSessionId(), 'json');
        $session->start();
        $session->put('errors', $this->getErrorBag());
        $session->flash('status', 'saved');

        try {
            $session->save();

            $this->fail('Expected the first session write to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to persist the session.', $exception->getMessage());
        }

        $this->assertInstanceOf(ViewErrorBag::class, $session->get('errors'));
        $this->assertSame(['status'], $session->get('_flash.new'));
        $this->assertSame([], $session->get('_flash.old'));
        $this->assertTrue($session->isStarted());

        $session->save();

        $this->assertInstanceOf(ViewErrorBag::class, $session->get('errors'));
        $this->assertSame([], $session->get('_flash.new'));
        $this->assertSame(['status'], $session->get('_flash.old'));
        $this->assertFalse($session->isStarted());
        $this->assertSame($payloads[0], $payloads[1]);
    }

    public function testConsecutiveJsonSavesKeepTheLiveErrorBag(): void
    {
        $handler = m::mock(SessionHandlerInterface::class);
        $handler->shouldReceive('read')->once()->andReturn(json_encode([]));
        $handler->shouldReceive('write')->twice()->andReturnTrue();

        $session = new Store('name', $handler, $this->getSessionId(), 'json');
        $session->start();
        $session->put('errors', $this->getErrorBag());

        $session->save();
        $session->save();

        $this->assertInstanceOf(ViewErrorBag::class, $session->get('errors'));
    }

    public function testStartingJsonSessionRetainsLiveErrorBagWhenStorageHasNone(): void
    {
        $handler = m::mock(SessionHandlerInterface::class);
        $handler->shouldReceive('read')->once()->andReturn(json_encode([]));

        $session = new Store('name', $handler, $this->getSessionId(), 'json');
        $errorBag = $this->getErrorBag();
        $session->put('errors', $errorBag);

        $session->start();

        $this->assertSame($errorBag, $session->get('errors'));
        $this->assertSame([
            'first_name' => [
                'Your first name is required',
                'Your first name must be at least 1 character',
            ],
        ], $errorBag->getBag('default')->getMessages());
    }

    public function testPersistedJsonErrorBagOverridesLiveErrorBagOnStart(): void
    {
        $handler = m::mock(SessionHandlerInterface::class);
        $handler->shouldReceive('read')->once()->andReturn(json_encode([
            'errors' => [
                'persisted' => [
                    'format' => ':message',
                    'messages' => [
                        'email' => ['The email address is invalid.'],
                    ],
                ],
            ],
        ]));

        $session = new Store('name', $handler, $this->getSessionId(), 'json');
        $liveErrorBag = $this->getErrorBag();
        $session->put('errors', $liveErrorBag);

        $session->start();

        $errorBag = $session->get('errors');

        $this->assertInstanceOf(ViewErrorBag::class, $errorBag);
        $this->assertNotSame($liveErrorBag, $errorBag);
        $this->assertFalse($errorBag->hasBag('default'));
        $this->assertSame(
            ['email' => ['The email address is invalid.']],
            $errorBag->getBag('persisted')->getMessages()
        );
    }

    public function testJsonEncodingFailureLeavesLiveStateUntouched(): void
    {
        $handler = m::mock(SessionHandlerInterface::class);
        $handler->shouldReceive('read')->once()->andReturn(json_encode([]));
        $handler->shouldReceive('write')->never();

        $session = new Store('name', $handler, $this->getSessionId(), 'json');
        $session->start();

        $recursive = [];
        $recursive['self'] = &$recursive;
        $session->put('recursive', $recursive);

        try {
            $session->save();

            $this->fail('Expected recursive session data to fail JSON encoding.');
        } catch (JsonException) {
            $this->assertTrue($session->isStarted());
            $this->assertTrue($session->has('recursive'));
        }
    }

    public function testValidationErrorsCanBeReadAsJson(): void
    {
        $session = $this->getSession('json');
        $session->getHandler()->shouldReceive('read')->once()->with($this->getSessionId())->andReturn(json_encode([
            'errors' => [
                'default' => [
                    'format' => '<p>:message</p>',
                    'messages' => [
                        'first_name' => [
                            'Your first name is required',
                            'Your first name must be at least 1 character',
                        ],
                    ],
                ],
            ],
        ]));
        $session->start();

        $errors = $session->get('errors');

        $this->assertInstanceOf(ViewErrorBag::class, $errors);
        $this->assertInstanceOf(MessageBag::class, $errors->getBags()['default']);
        $this->assertEquals('<p>:message</p>', $errors->getBags()['default']->getFormat());
        $this->assertEquals(['first_name' => [
            'Your first name is required',
            'Your first name must be at least 1 character',
        ]], $errors->getBags()['default']->getMessages());
    }

    public function testItIsMacroable(): void
    {
        $this->getSession()->macro('foo', function () {
            return 'macroable';
        });

        $this->assertSame('macroable', $this->getSession()->foo());
    }

    public function testFlushStateClearsMacros(): void
    {
        Store::macro('foo', function () {
            return 'macroable';
        });

        $this->assertTrue(Store::hasMacro('foo'));

        Store::flushState();

        $this->assertFalse(Store::hasMacro('foo'));
    }

    public function testSessionIdLengthConstant(): void
    {
        $session = $this->getSession();
        $id = $session->getId();
        $this->assertSame(40, strlen($id));
        $this->assertTrue($session->isValidId($id));
        $this->assertFalse($session->isValidId(str_repeat('a', 39)));
        $this->assertFalse($session->isValidId(str_repeat('a', 41)));
    }

    public function testPreviousUri(): void
    {
        $session = $this->getSession();
        $session->setPreviousUrl('https://example.com/foo');

        $uri = $session->previousUri();
        $this->assertInstanceOf(Uri::class, $uri);
        $this->assertSame('https://example.com/foo', (string) $uri);
    }

    public function testPreviousUriThrowsWhenNoPreviousUrl(): void
    {
        $session = $this->getSession();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to generate URI instance for previous URL. No previous URL detected.');

        $session->previousUri();
    }

    public function testPreviousRoute(): void
    {
        $session = $this->getSession();
        $this->assertNull($session->previousRoute());

        $session->setPreviousRoute('home.index');
        $this->assertSame('home.index', $session->previousRoute());
    }

    public function testSetPreviousRoute(): void
    {
        $session = $this->getSession();
        $session->setPreviousRoute('dashboard');
        $this->assertSame('dashboard', $session->get('_previous.route'));

        $session->setPreviousRoute(null);
        $this->assertNull($session->get('_previous.route'));
    }

    protected function getErrorBag(): ViewErrorBag
    {
        $messageBag = new MessageBag([
            'first_name' => [
                'Your first name is required',
                'Your first name must be at least 1 character',
            ],
        ]);
        $messageBag->setFormat('<p>:message</p>');

        return (new ViewErrorBag)->put('default', $messageBag);
    }

    public function getSession(string $serialization = 'php'): Store
    {
        return new Store(
            $this->getSessionName(),
            m::mock(SessionHandlerInterface::class),
            $this->getSessionId(),
            $serialization
        );
    }

    protected function getSessionId(): string
    {
        return 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    }

    protected function getSessionName(): string
    {
        return 'name';
    }
}

enum SessionTestKey: string
{
    case User = 'user';
    case Settings = 'settings';
    case Preference = 'preference';
}
