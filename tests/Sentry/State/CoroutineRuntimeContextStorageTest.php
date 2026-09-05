<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\State;

use ArrayObject;
use Hypervel\Context\CoroutineContext;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Channel;
use Hypervel\Sentry\State\CoroutineRuntimeContextStorage;
use Hypervel\Tests\TestCase;
use Sentry\State\Hub;
use Sentry\State\RuntimeContext;

class CoroutineRuntimeContextStorageTest extends TestCase
{
    public function testStoresAndRemovesRuntimeContext(): void
    {
        $storage = new CoroutineRuntimeContextStorage;
        $runtimeContext = $this->createRuntimeContext();

        $this->assertNull($storage->get());
        $this->assertNull($storage->remove());

        $storage->set($runtimeContext);

        $this->assertSame($runtimeContext, $storage->get());
        $this->assertSame($runtimeContext, $storage->remove());
        $this->assertNull($storage->get());
        $this->assertNull($storage->remove());
    }

    public function testParentAndChildReleaseSharedRuntimeContextAfterFinalOwner(): void
    {
        $storage = new CoroutineRuntimeContextStorage;
        $runtimeContext = $this->createRuntimeContext();
        $childReady = new Channel(1);
        $releaseChild = new Channel(1);
        $childResult = new Channel(1);

        $storage->set($runtimeContext);

        Coroutine::create(static function () use ($storage, $childReady, $releaseChild, $childResult): void {
            $context = CoroutineContext::getContainer();
            $parentContext = CoroutineContext::getContainer(Coroutine::parentId());

            $childReady->push([
                $storage->inheritFrom($context, $parentContext),
                $storage->get(),
            ]);
            $releaseChild->pop();
            $childResult->push($storage->remove());
        });

        [$inherited, $childRuntimeContext] = $childReady->pop();

        $this->assertTrue($inherited);
        $this->assertSame($runtimeContext, $childRuntimeContext);
        $this->assertNull($storage->remove());

        $releaseChild->push(true);

        $this->assertSame($runtimeContext, $childResult->pop());
    }

    public function testChildReleaseKeepsRuntimeContextForParent(): void
    {
        $storage = new CoroutineRuntimeContextStorage;
        $runtimeContext = $this->createRuntimeContext();
        $childResult = new Channel(1);

        $storage->set($runtimeContext);

        Coroutine::create(static function () use ($storage, $childResult): void {
            $context = CoroutineContext::getContainer();
            $parentContext = CoroutineContext::getContainer(Coroutine::parentId());

            $storage->inheritFrom($context, $parentContext);
            $childResult->push($storage->remove());
        });

        $this->assertNull($childResult->pop());
        $this->assertSame($runtimeContext, $storage->get());
        $this->assertSame($runtimeContext, $storage->remove());
    }

    public function testDoesNotRetainAnAlreadyPopulatedChildContextTwice(): void
    {
        $storage = new CoroutineRuntimeContextStorage;
        $runtimeContext = $this->createRuntimeContext();
        $childResult = new Channel(1);

        $storage->set($runtimeContext);

        Coroutine::create(static function () use ($storage, $childResult): void {
            $context = CoroutineContext::getContainer();
            $parentContext = CoroutineContext::getContainer(Coroutine::parentId());

            $childResult->push([
                $storage->inheritFrom($context, $parentContext),
                $storage->inheritFrom($context, $parentContext),
                $storage->remove(),
            ]);
        });

        [$firstInheritance, $secondInheritance, $releasedRuntimeContext] = $childResult->pop();

        $this->assertTrue($firstInheritance);
        $this->assertFalse($secondInheritance);
        $this->assertNull($releasedRuntimeContext);
        $this->assertSame($runtimeContext, $storage->remove());
    }

    public function testDoesNotInheritWithoutAParentRuntimeContext(): void
    {
        $storage = new CoroutineRuntimeContextStorage;

        $this->assertFalse($storage->inheritFrom(new ArrayObject, null));
        $this->assertFalse($storage->inheritFrom(new ArrayObject, new ArrayObject));
    }

    private function createRuntimeContext(): RuntimeContext
    {
        return new RuntimeContext('test', new Hub);
    }
}
