<?php

declare(strict_types=1);

namespace Hypervel\Tests\Context;

use Hypervel\Context\CoroutineContext;
use Hypervel\Context\NonCopyableContext;
use Hypervel\Context\ReplicableContext;
use Hypervel\Context\RequestContext;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Coroutine\Waiter;
use Hypervel\Engine\Channel;
use Hypervel\Http\Request;
use Hypervel\Tests\Context\Fixtures\ThrowingReplicableContext;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;
use stdClass;

use function Hypervel\Coroutine\parallel;

class ContextCoroutineTest extends TestCase
{
    public function testCaptureFromCurrentCoroutine(): void
    {
        CoroutineContext::set('capture.first', 'first');
        CoroutineContext::set('capture.second', 'second');

        $captured = CoroutineContext::captureFrom();

        $this->assertSame('first', $captured['capture.first']);
        $this->assertSame('second', $captured['capture.second']);
    }

    public function testCaptureFromCurrentCoroutineWithSelectiveKeys(): void
    {
        CoroutineContext::set('capture.wanted', 'yes');
        CoroutineContext::set('capture.unwanted', 'no');

        $this->assertSame(
            ['capture.wanted' => 'yes'],
            CoroutineContext::captureFrom(['capture.wanted']),
        );
    }

    public function testCaptureOmitsNonCopyableValuesForAllAndSelectedKeys(): void
    {
        CoroutineContext::set('capture.copyable', 'value');
        CoroutineContext::set('capture.non-copyable', new class implements NonCopyableContext {
        });

        $this->assertSame(
            ['capture.copyable' => 'value'],
            CoroutineContext::captureFrom(),
        );
        $this->assertSame(
            [],
            CoroutineContext::captureFrom(['capture.non-copyable']),
        );
    }

    public function testNonCopyableMarkerTakesPrecedenceOverReplication(): void
    {
        $value = new class implements NonCopyableContext, ReplicableContext {
            public bool $replicated = false;

            public function replicate(): static
            {
                $this->replicated = true;

                return clone $this;
            }
        };

        CoroutineContext::set('capture.both-markers', $value);

        $this->assertSame([], CoroutineContext::captureFrom());
        $this->assertFalse($value->replicated);
    }

    public function testCaptureFromExplicitCoroutine(): void
    {
        $sourceReady = new Channel(1);
        $releaseSource = new Channel(1);

        Coroutine::create(static function () use ($sourceReady, $releaseSource): void {
            CoroutineContext::set('capture.source', 'source');
            CoroutineContext::set('capture.other', 'other');
            $sourceReady->push(Coroutine::id());
            $releaseSource->pop();
        });

        $sourceId = $sourceReady->pop(1.0);

        try {
            $this->assertSame(
                [
                    'capture.source' => 'source',
                    'capture.other' => 'other',
                ],
                CoroutineContext::captureFrom(fromCoroutineId: $sourceId),
            );
            $this->assertSame(
                ['capture.source' => 'source'],
                CoroutineContext::captureFrom(['capture.source'], $sourceId),
            );
        } finally {
            $releaseSource->push(true);
        }
    }

    public function testCopy(): void
    {
        CoroutineContext::set('test.store.id', $uid = uniqid());
        $id = Coroutine::id();
        parallel([
            function () use ($id, $uid) {
                CoroutineContext::copyFrom($id, ['test.store.id']);
                $this->assertSame($uid, CoroutineContext::get('test.store.id'));
            },
        ]);
    }

    public function testCopyAfterSet(): void
    {
        CoroutineContext::set('test.store.id', $uid = uniqid());
        $id = Coroutine::id();
        parallel([
            function () use ($id, $uid) {
                CoroutineContext::set('test.store.name', 'Hypervel');
                CoroutineContext::copyFrom($id, ['test.store.id']);
                $this->assertSame($uid, CoroutineContext::get('test.store.id'));

                // CoroutineContext::copyFrom() merges — existing values are preserved.
                $this->assertSame('Hypervel', CoroutineContext::get('test.store.name'));
            },
        ]);
    }

    public function testContextChangeAfterCopy(): void
    {
        $obj = new stdClass;
        $obj->id = $uid = uniqid();

        CoroutineContext::set('test.store.id', $obj);
        CoroutineContext::set('test.store.useless.id', 1);
        $id = Coroutine::id();
        $tid = uniqid();
        parallel([
            function () use ($id, $uid, $tid) {
                CoroutineContext::copyFrom($id, ['test.store.id']);
                $obj = CoroutineContext::get('test.store.id');
                $this->assertSame($uid, $obj->id);
                $obj->id = $tid;
                $this->assertFalse(CoroutineContext::has('test.store.useless.id'));
            },
        ]);

        $this->assertSame($tid, CoroutineContext::get('test.store.id')->id);
    }

    public function testContextFromNull(): void
    {
        $res = CoroutineContext::get('id', $default = 'Hello World!', -1);
        $this->assertSame($default, $res);

        $res = CoroutineContext::get('id', null, -1);
        $this->assertSame(null, $res);

        $this->assertFalse(CoroutineContext::has('id', -1));

        CoroutineContext::copyFrom(-1);

        parallel([
            function () {
                CoroutineContext::set('id', $id = uniqid());
                CoroutineContext::copyFrom(-1, ['id']);
                $this->assertSame($id, CoroutineContext::get('id'));
            },
        ]);
    }

    public function testRequestContextWithCoroutineId(): void
    {
        $request = m::mock(Request::class);
        RequestContext::set($request);
        $id = Coroutine::id();
        (new Waiter)->wait(function () use ($id, $request) {
            $this->assertSame($request, RequestContext::get($id));
        });
    }

    public function testContextOverrideWithCoroutineId(): void
    {
        $id = Coroutine::id();
        $value = uniqid();
        CoroutineContext::override('override.id.coroutine_id', fn () => $value);
        (new Waiter)->wait(function () use ($id, $value) {
            CoroutineContext::override(
                'override.id.coroutine_id',
                function ($v) use ($value) {
                    $this->assertSame($v, $value);
                    return '123';
                },
                $id
            );
        });

        $this->assertSame('123', CoroutineContext::get('override.id.coroutine_id'));
    }

    public function testContextGetOrSetWithCoroutineId(): void
    {
        $id = Coroutine::id();
        $value = uniqid();
        CoroutineContext::getOrSet('get_or_set.id.coroutine_id', fn () => $value);
        (new Waiter)->wait(function () use ($id, $value) {
            $res = CoroutineContext::getOrSet('get_or_set.id.coroutine_id', fn () => '123', $id);
            $this->assertSame($res, $value);
        });
    }

    public function testFailedReplicationDoesNotPartiallyModifyTheDestination(): void
    {
        $sourceReady = new Channel(1);
        $releaseSource = new Channel(1);

        Coroutine::create(static function () use ($sourceReady, $releaseSource): void {
            CoroutineContext::set('stable', 'source');
            CoroutineContext::set('throwing', new ThrowingReplicableContext);
            $sourceReady->push(Coroutine::id());
            $releaseSource->pop();
        });

        CoroutineContext::set('stable', 'destination');
        CoroutineContext::set('untouched', 'value');

        try {
            CoroutineContext::copyFrom($sourceReady->pop(1.0));
            $this->fail('Expected context replication to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to replicate context.', $exception->getMessage());
        } finally {
            $releaseSource->push(true);
        }

        $this->assertSame('destination', CoroutineContext::get('stable'));
        $this->assertSame('value', CoroutineContext::get('untouched'));
        $this->assertFalse(CoroutineContext::has('throwing'));
    }

    public function testCopyFromPreservesDestinationValueForOmittedSourceKey(): void
    {
        $sourceReady = new Channel(1);
        $releaseSource = new Channel(1);

        Coroutine::create(static function () use ($sourceReady, $releaseSource): void {
            CoroutineContext::set('copyable', 'source');
            CoroutineContext::set('owned-resource', new class implements NonCopyableContext {
            });
            $sourceReady->push(Coroutine::id());
            $releaseSource->pop();
        });

        $destinationResource = new stdClass;
        CoroutineContext::set('copyable', 'destination');
        CoroutineContext::set('owned-resource', $destinationResource);
        CoroutineContext::set('untouched', 'value');

        try {
            CoroutineContext::copyFrom($sourceReady->pop(1.0));
        } finally {
            $releaseSource->push(true);
        }

        $this->assertSame('source', CoroutineContext::get('copyable'));
        $this->assertSame($destinationResource, CoroutineContext::get('owned-resource'));
        $this->assertSame('value', CoroutineContext::get('untouched'));
    }
}
