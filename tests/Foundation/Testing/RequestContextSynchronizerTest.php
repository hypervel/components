<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing;

use ArrayObject;
use Hypervel\Context\CoroutineContext;
use Hypervel\Coroutine\Waiter;
use Hypervel\Database\DatabaseTransactionRecord;
use Hypervel\Database\DatabaseTransactionState;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Foundation\Testing\RequestContextSynchronizer;
use Hypervel\Tests\TestCase;
use PDO;

class RequestContextSynchronizerTest extends TestCase
{
    public function testTransactionSyncOnlyReplacesTheRetainedRoleOwnersRecords(): void
    {
        $base = new DatabaseTransactionRecord('testing', 1);
        $writer = new DatabaseTransactionRecord('testing::write', 1);
        $savepoint = new DatabaseTransactionRecord('testing::write', 2, $writer);
        $source = new DatabaseTransactionState;
        $source->pending->push($writer);
        $source->committed->push($savepoint);
        $source->current['testing::write'] = $writer;

        $target = new DatabaseTransactionState;
        $target->pending->push($base, $writer, $savepoint);
        $target->current = ['testing' => $base, 'testing::write' => $savepoint];

        $connection = new SQLiteConnection(new PDO('sqlite::memory:'), config: ['name' => 'testing']);
        $connection->setReadWriteType('write');
        $synchronizer = new RequestContextSynchronizer;
        $synchronizer->syncTransactionState($source, $target, ['testing::write' => $connection]);

        $this->assertSame([$base, $writer], $target->pending->all());
        $this->assertSame([$savepoint], $target->committed->all());
        $this->assertSame(['testing' => $base, 'testing::write' => $writer], $target->current);

        $synchronizer->syncTransactionState(new DatabaseTransactionState, $target, ['testing::write' => $connection]);

        $this->assertSame([$base], $target->pending->all());
        $this->assertTrue($target->committed->isEmpty());
        $this->assertSame(['testing' => $base], $target->current);
    }

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
