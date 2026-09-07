<?php

declare(strict_types=1);

namespace Hypervel\Tests\OpenTelemetry\Context;

use Hypervel\Context\CoroutineContext;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Channel;
use Hypervel\OpenTelemetry\Context\CoroutineContextStorage;
use Hypervel\Tests\TestCase;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ContextKeyInterface;
use OpenTelemetry\Context\ContextStorageInterface;
use OpenTelemetry\Context\ExecutionContextAwareInterface;
use OpenTelemetry\Context\ScopeInterface;

class CoroutineContextStorageTest extends TestCase
{
    private ContextStorageInterface&ExecutionContextAwareInterface $previousStorage;

    private CoroutineContextStorage $storage;

    private ContextInterface $baseContext;

    /** @var ContextKeyInterface<string> */
    private ContextKeyInterface $key;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousStorage = Context::storage();
        $this->key = Context::createKey('hypervel.opentelemetry.test');
        $this->baseContext = Context::getRoot()->with($this->key, 'base');
    }

    protected function setUpInCoroutine(): void
    {
        $this->storage = new CoroutineContextStorage($this->baseContext);
        Context::setStorage($this->storage);
    }

    protected function tearDown(): void
    {
        Context::setStorage($this->previousStorage);

        parent::tearDown();
    }

    public function testUsesTheConfiguredBaseUntilAContextIsAttached(): void
    {
        $this->assertSame($this->baseContext, $this->storage->current());
        $this->assertSame($this->baseContext, Context::getCurrent());
        $this->assertNull($this->storage->scope());
        $this->assertFalse(CoroutineContext::has(CoroutineContextStorage::CONTEXT_KEY));

        $scope = $this->storage->attach($this->context('attached'));

        $this->assertTrue(CoroutineContext::has(CoroutineContextStorage::CONTEXT_KEY));
        $this->assertSame('attached', $this->storage->current()->get($this->key));
        $this->assertSame($scope, $this->storage->scope());
        $this->assertSame(0, $scope->detach());
        $this->assertSame($this->baseContext, $this->storage->current());
        $this->assertNull($this->storage->scope());
    }

    public function testScopesExposeTheirExactContextAndIsolatedLocalStorage(): void
    {
        $outerContext = $this->context('outer');
        $innerContext = $this->context('inner');
        $outer = $this->storage->attach($outerContext);
        $outer['value'] = 'outer';
        $inner = $this->storage->attach($innerContext);

        $this->assertSame($outerContext, $outer->context());
        $this->assertSame($innerContext, $inner->context());
        $this->assertFalse(isset($inner['value']));

        $inner['value'] = 'inner';

        $this->assertSame('outer', $outer['value']);
        $this->assertSame('inner', $inner['value']);
        unset($inner['value']);
        $this->assertFalse(isset($inner['value']));

        $this->assertSame(0, $inner->detach());
        $this->assertSame($outer, $this->storage->scope());
        $this->assertSame(0, $outer->detach());
    }

    public function testOutOfOrderAndRepeatedDetachReturnContractFlagsWithoutCorruptingNewerScopes(): void
    {
        $outer = $this->storage->attach($this->context('outer'));
        $middle = $this->storage->attach($this->context('middle'));
        $inner = $this->storage->attach($this->context('inner'));

        $this->assertSame(ScopeInterface::MISMATCH | 2, $outer->detach());
        $this->assertSame('inner', $this->storage->current()->get($this->key));
        $this->assertSame(ScopeInterface::DETACHED, $outer->detach());
        $this->assertSame(ScopeInterface::MISMATCH | 1, $middle->detach());
        $this->assertSame('inner', $this->storage->current()->get($this->key));
        $this->assertSame(0, $inner->detach());
        $this->assertSame($this->baseContext, $this->storage->current());
    }

    public function testUnkeyedForkInheritsAnIndependentSnapshotWithoutParentScopeNodes(): void
    {
        $parentScope = $this->storage->attach($this->context('parent'));
        $childReady = new Channel(1);
        $releaseChild = new Channel(1);
        $observed = [];

        $childId = Coroutine::fork(function () use ($childReady, $releaseChild, &$observed): void {
            $observed['inherited'] = Context::getCurrent()->get($this->key);
            $observed['scope'] = $this->storage->scope();
            $scope = $this->storage->attach($this->context('child'));
            $observed['child'] = Context::getCurrent()->get($this->key);
            $childReady->push(true);
            $releaseChild->pop();
            $observed['detach'] = $scope->detach();
            $observed['restored'] = Context::getCurrent()->get($this->key);
        });

        $childReady->pop(1.0);
        $this->assertSame('parent', Context::getCurrent()->get($this->key));
        $releaseChild->push(true);
        Coroutine::join([$childId], 1.0);

        $this->assertSame('parent', $observed['inherited']);
        $this->assertNull($observed['scope']);
        $this->assertSame('child', $observed['child']);
        $this->assertSame(0, $observed['detach']);
        $this->assertSame('parent', $observed['restored']);
        $this->assertSame(0, $parentScope->detach());
    }

    public function testKeyedForkAndCreateStartFromTheBaseContext(): void
    {
        $parentScope = $this->storage->attach($this->context('parent'));
        CoroutineContext::set('copied-key', 'copied');
        $observed = [];

        $keyedFork = Coroutine::fork(function () use (&$observed): void {
            $observed['keyed'] = Context::getCurrent()->get($this->key);
            $observed['copied'] = CoroutineContext::get('copied-key');
        }, ['copied-key']);
        $created = Coroutine::create(function () use (&$observed): void {
            $observed['created'] = Context::getCurrent()->get($this->key);
        });

        Coroutine::join([$keyedFork, $created], 1.0);

        $this->assertSame('base', $observed['keyed']);
        $this->assertSame('copied', $observed['copied']);
        $this->assertSame('base', $observed['created']);
        $this->assertSame('parent', Context::getCurrent()->get($this->key));
        $this->assertSame(0, $parentScope->detach());
    }

    public function testKeyedForkInheritsContextOnlyWhenTheOpenTelemetryKeyIsAllowed(): void
    {
        $parentScope = $this->storage->attach($this->context('parent'));
        $observed = [];

        $child = Coroutine::fork(function () use (&$observed): void {
            $observed['context'] = Context::getCurrent()->get($this->key);
            $observed['scope'] = $this->storage->scope();
        }, [CoroutineContextStorage::CONTEXT_KEY]);

        Coroutine::join([$child], 1.0);

        $this->assertSame('parent', $observed['context']);
        $this->assertNull($observed['scope']);
        $this->assertSame(0, $parentScope->detach());
    }

    public function testScopeCanBeDetachedFromAnotherCoroutineWithoutChangingThatCoroutinesContext(): void
    {
        $scope = $this->storage->attach($this->context('parent'));
        $observed = [];

        $child = Coroutine::create(function () use ($scope, &$observed): void {
            $observed['before'] = Context::getCurrent()->get($this->key);
            $observed['detach'] = $scope->detach();
            $observed['after'] = Context::getCurrent()->get($this->key);
        });

        Coroutine::join([$child], 1.0);

        $this->assertSame('base', $observed['before']);
        $this->assertSame(ScopeInterface::INACTIVE, $observed['detach']);
        $this->assertSame('base', $observed['after']);
        $this->assertSame($this->baseContext, Context::getCurrent());
        $this->assertSame(ScopeInterface::DETACHED, $scope->detach());
    }

    private function context(string $value): ContextInterface
    {
        return $this->baseContext->with($this->key, $value);
    }
}
