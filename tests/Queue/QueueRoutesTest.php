<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue\QueueRoutesTest;

use Hypervel\Foundation\Queue\Queueable;
use Hypervel\Queue\Attributes\Queue as QueueAttribute;
use Hypervel\Queue\QueueRoutes;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\TestWith;

class QueueRoutesTest extends TestCase
{
    public function testSet(): void
    {
        $defaults = new QueueRoutes;

        $defaults->set(QueueRoutes::class, 'some-queue');
        $defaults->set(BaseNotification::class, 'some-queue', 'some-connection');

        $this->assertSame([
            QueueRoutes::class => [null, 'some-queue'],
            BaseNotification::class => ['some-connection', 'some-queue'],
        ], $defaults->all());

        // Ensure same class overrides
        $defaults->set([
            QueueRoutes::class => 'queue-many',
            SomeJob::class => 'important',
        ]);

        $this->assertSame(
            [
                QueueRoutes::class => 'queue-many',
                BaseNotification::class => ['some-connection', 'some-queue'],
                SomeJob::class => 'important',
            ],
            $defaults->all()
        );
    }

    public function testGetQueue(): void
    {
        $defaults = new QueueRoutes;

        $defaults->set([
            BaseNotification::class => 'notifications',
            CustomTrait::class => 'jobs',
            PaymentContract::class => 'payments',
        ]);

        // No queue set
        $defaults->set(PaymentContract::class, connection: 'payment-connection');

        $this->assertSame('notifications', $defaults->getQueue(new FinanceNotification));
        $this->assertSame('jobs', $defaults->getQueue(new SomeJob));
        $this->assertNull($defaults->getQueue(new Payment));
    }

    public function testGetConnection(): void
    {
        $defaults = new QueueRoutes;

        $defaults->set([
            BaseNotification::class => ['notification-connection', 'notifications'],
            CustomTrait::class => ['job-connection', 'jobs'],
        ]);

        // No connection set
        $defaults->set(PaymentContract::class, 'payments');

        $this->assertSame('notification-connection', $defaults->getConnection(new FinanceNotification));
        $this->assertSame('job-connection', $defaults->getConnection(new SomeJob));
        $this->assertNull($defaults->getConnection(new Payment));
    }

    public function testStringRouteDefaultsToQueueNotConnection(): void
    {
        $defaults = new QueueRoutes;

        $defaults->set([BaseNotification::class => 'notifications']);

        $this->assertSame('notifications', $defaults->getQueue(new FinanceNotification));
        $this->assertNull($defaults->getConnection(new FinanceNotification));
    }

    public function testForwardRewritesName(): void
    {
        $defaults = new QueueRoutes;

        $defaults->forward('reports', 'audit');

        $this->assertSame('audit', $defaults->forwardedQueue('reports'));
        $this->assertSame('audit', $defaults->forwardedQueue('reports', 'cloud'));
        $this->assertSame('other', $defaults->forwardedQueue('other'));
    }

    public function testForwardIsScopedToConnection(): void
    {
        $defaults = new QueueRoutes;

        $defaults->forward('reports', 'audit', 'cloud');

        $this->assertSame('audit', $defaults->forwardedQueue('reports', 'cloud'));
        $this->assertSame('reports', $defaults->forwardedQueue('reports', 'redis'));
        $this->assertSame('reports', $defaults->forwardedQueue('reports'));
    }

    public function testForwardWithJustConnectionKeepsName(): void
    {
        $defaults = new QueueRoutes;

        $defaults->forward('reports', connection: 'cloud');

        $this->assertSame('reports', $defaults->forwardedQueue('reports', 'cloud'));
    }

    public function testForwardSetsConnectionByQueueName(): void
    {
        $defaults = new QueueRoutes;

        $defaults->forward('reports', 'audit', 'cloud');

        $this->assertSame('cloud', $defaults->getConnection((new SomeJob)->onQueue('reports')));
        $this->assertNull($defaults->getConnection((new SomeJob)->onQueue('other')));
        $this->assertNull($defaults->getConnection(new SomeJob));
    }

    public function testForwardMatchesQueueAttribute(): void
    {
        $defaults = new QueueRoutes;

        $defaults->forward('reports', 'audit', 'cloud');

        $this->assertSame('cloud', $defaults->getConnection(new AttributeForwardedJob));
    }

    public function testForwardAcceptsArray(): void
    {
        $defaults = new QueueRoutes;

        $defaults->forward([
            'reports' => 'audit',
            'emails' => 'mail',
        ], connection: 'cloud');

        $this->assertSame('audit', $defaults->forwardedQueue('reports', 'cloud'));
        $this->assertSame('mail', $defaults->forwardedQueue('emails', 'cloud'));
        $this->assertSame('reports', $defaults->forwardedQueue('reports', 'redis'));
    }

    public function testForwardResolvesEnums(): void
    {
        $defaults = new QueueRoutes;

        $defaults->forward(QueueName::Payments, 'settlements', ConnectionName::Redis);

        $this->assertSame('settlements', $defaults->forwardedQueue('payments', 'redis'));
        $this->assertSame('payments', $defaults->forwardedQueue('payments', 'sqs'));
    }

    #[TestWith(['reports'])]
    #[TestWith([[null, 'reports']])]
    public function testForwardedConnectionUsesClassRouteWithoutConnection(array|string $route): void
    {
        $defaults = new QueueRoutes;
        $defaults->set([SomeJob::class => $route]);
        $defaults->forward('reports', 'audit', 'cloud');
        $defaults->forward('updates', 'notifications', 'redis');

        $this->assertSame('cloud', $defaults->getConnection(new SomeJob));
        $this->assertSame('redis', $defaults->getConnection((new SomeJob)->onQueue('updates')));
        $this->assertSame('redis', $defaults->getConnection((new SomeJob)->onQueue('reports'), 'updates'));

        $defaults->set(SomeJob::class, 'reports', 'explicit');

        $this->assertSame('explicit', $defaults->getConnection(new SomeJob, 'updates'));
    }

    public function testForwardNormalizesIntegerAndUnitEnumsWithoutLosingZero(): void
    {
        $defaults = new QueueRoutes;
        $defaults->forward(QueueRouteIntegerIdentifier::Queue, QueueRouteIntegerIdentifier::Zero, QueueRouteIntegerIdentifier::Zero);

        $this->assertSame('0', $defaults->forwardedQueue('1', '0'));
        $this->assertSame('0', $defaults->getConnection((new SomeJob)->onQueue(QueueRouteIntegerIdentifier::Queue)));

        $defaults->forward(['0' => QueueName::Payments], connection: QueueRouteUnitIdentifier::Connection);

        $this->assertSame('payments', $defaults->forwardedQueue('0', 'Connection'));
        $this->assertSame('Connection', $defaults->getConnection((new SomeJob)->onQueue(QueueRouteIntegerIdentifier::Zero)));
    }

    public function testConnectionScopedForwardingLeavesUnscopedForwardsToTheStorageDriver(): void
    {
        $defaults = new QueueRoutes;
        $defaults->forward('reports', 'processing', 'failover');
        $defaults->forward('processing', 'archive');

        $this->assertSame('processing', $defaults->forwardedQueueForConnection('reports', 'failover'));
        $this->assertSame('reports', $defaults->forwardedQueueForConnection('reports', 'redis'));
        $this->assertSame('reports', $defaults->forwardedQueueForConnection('reports', null));
        $this->assertSame('processing', $defaults->forwardedQueueForConnection('processing', 'failover'));
        $this->assertSame('archive', $defaults->forwardedQueue('processing', 'redis'));
    }

    public function testEnumsAreResolved(): void
    {
        $defaults = new QueueRoutes;

        $defaults->set(SomeJob::class, QueueName::Payments, ConnectionName::Redis);

        $this->assertSame('payments', $defaults->getQueue(new SomeJob));
        $this->assertSame('redis', $defaults->getConnection(new SomeJob));

        $defaults->set([SomeJob::class => [ConnectionName::Redis, QueueName::Payments]]);

        $this->assertSame('payments', $defaults->getQueue(new SomeJob));
        $this->assertSame('redis', $defaults->getConnection(new SomeJob));
    }

    public function testEnumRoutesAreNormalizedAndScalarRoutesRemainQueueOnly(): void
    {
        $defaults = new QueueRoutes;

        $defaults->set([
            SomeJob::class => QueueRouteIntegerIdentifier::Zero,
            BaseNotification::class => [
                QueueRouteUnitIdentifier::Connection,
                QueueRouteIntegerIdentifier::Queue,
            ],
        ]);

        $this->assertSame('0', $defaults->getQueue(new SomeJob));
        $this->assertNull($defaults->getConnection(new SomeJob));
        $this->assertSame('Connection', $defaults->getConnection(new FinanceNotification));
        $this->assertSame('1', $defaults->getQueue(new FinanceNotification));
    }
}

enum QueueName: string
{
    case Payments = 'payments';
}

enum ConnectionName: string
{
    case Redis = 'redis';
}

trait CustomTrait
{
}

class SomeJob
{
    use Queueable;
    use CustomTrait;
}

#[QueueAttribute('reports')]
class AttributeForwardedJob
{
    use Queueable;
}

class BaseNotification
{
    use Queueable;
}

class FinanceNotification extends BaseNotification
{
}

interface PaymentContract
{
}

class Payment implements PaymentContract
{
}

enum QueueRouteUnitIdentifier
{
    case Connection;
}

enum QueueRouteIntegerIdentifier: int
{
    case Zero = 0;
    case Queue = 1;
}
