<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing;

use ArrayObject;
use Hypervel\Context\CoroutineContext;
use Hypervel\Coroutine\Waiter;
use Hypervel\Foundation\Testing\RequestContextSynchronizer;
use Hypervel\Tests\TestCase;

class RequestContextSynchronizerTest extends TestCase
{
    public function testSyncContextKeysToParentCopiesPresentKeysAndForgetsMissingKeys(): void
    {
        CoroutineContext::set('foundation.testing.present', 'old');
        CoroutineContext::set('foundation.testing.missing', 'old');

        (new Waiter)->wait(function (): void {
            CoroutineContext::set('foundation.testing.present', 'new');
            CoroutineContext::forget('foundation.testing.missing');

            (new RequestContextSynchronizer)->syncContextKeysToParent([
                'foundation.testing.present',
                'foundation.testing.missing',
            ]);
        }, copyContext: true);

        $this->assertSame('new', CoroutineContext::get('foundation.testing.present'));
        $this->assertFalse(CoroutineContext::has('foundation.testing.missing'));
    }

    public function testSyncSnapshotToParentCopiesPresentKeysAndForgetsMissingKeys(): void
    {
        CoroutineContext::set('foundation.testing.snapshot.present', 'old');
        CoroutineContext::set('foundation.testing.snapshot.missing', 'old');

        (new Waiter)->wait(function (): void {
            (new RequestContextSynchronizer)->syncSnapshotToParent([
                'foundation.testing.snapshot.present' => 'new',
            ], [
                'foundation.testing.snapshot.present',
                'foundation.testing.snapshot.missing',
            ]);
        }, copyContext: true);

        $this->assertSame('new', CoroutineContext::get('foundation.testing.snapshot.present'));
        $this->assertFalse(CoroutineContext::has('foundation.testing.snapshot.missing'));
    }

    public function testSyncSnapshotToParentSupportsArrayAccessSnapshots(): void
    {
        CoroutineContext::set('foundation.testing.array-access.present', 'old');
        CoroutineContext::set('foundation.testing.array-access.missing', 'old');

        (new Waiter)->wait(function (): void {
            (new RequestContextSynchronizer)->syncSnapshotToParent(new ArrayObject([
                'foundation.testing.array-access.present' => 'new',
            ]), [
                'foundation.testing.array-access.present',
                'foundation.testing.array-access.missing',
            ]);
        }, copyContext: true);

        $this->assertSame('new', CoroutineContext::get('foundation.testing.array-access.present'));
        $this->assertFalse(CoroutineContext::has('foundation.testing.array-access.missing'));
    }
}
