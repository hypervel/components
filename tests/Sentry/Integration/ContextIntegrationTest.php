<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Integration;

use Exception;
use Hypervel\Sentry\Integration\ContextIntegration;
use Hypervel\Support\Facades\Context;
use Hypervel\Tests\Sentry\SentryTestCase;
use Sentry\EventType;

use function Sentry\captureException;

class ContextIntegrationTest extends SentryTestCase
{
    public function testContextIntegrationIsRegistered()
    {
        $integration = $this->getSentryHubFromContainer()->getIntegration(ContextIntegration::class);

        $this->assertInstanceOf(ContextIntegration::class, $integration);
    }

    public function testExceptionIsCapturedWithContext()
    {
        $this->setupTestContext();

        captureException(new Exception('Context test'));

        $event = $this->getLastSentryEvent();

        $this->assertNotNull($event);
        $this->assertEquals($event->getType(), EventType::event());
        $this->assertContextIsCaptured($event->getContexts());
    }

    public function testExceptionIsCapturedWithoutContextIfEmpty()
    {
        captureException(new Exception('Context test'));

        $event = $this->getLastSentryEvent();

        $this->assertNotNull($event);
        $this->assertEquals($event->getType(), EventType::event());
        $this->assertArrayNotHasKey('hypervel', $event->getContexts());
    }

    public function testExceptionIsCapturedWithoutContextIfOnlyHidden()
    {
        Context::addHidden('hidden', 'value');

        captureException(new Exception('Context test'));

        $event = $this->getLastSentryEvent();

        $this->assertNotNull($event);
        $this->assertEquals($event->getType(), EventType::event());
        $this->assertArrayNotHasKey('hypervel', $event->getContexts());
    }

    public function testTransactionIsCapturedWithContext()
    {
        $this->setupTestContext();

        $transaction = $this->startTransaction();
        $transaction->setSampled(true);
        $transaction->finish();

        $event = $this->getLastSentryEvent();

        $this->assertNotNull($event);
        $this->assertEquals($event->getType(), EventType::transaction());
        $this->assertContextIsCaptured($event->getContexts());
    }

    private function setupTestContext(): void
    {
        Context::flush();
        Context::add('foo', 'bar');
        Context::addHidden('hidden', 'value');
    }

    private function assertContextIsCaptured(array $context): void
    {
        $this->assertArrayHasKey('hypervel', $context);
        $this->assertArrayHasKey('foo', $context['hypervel']);
        $this->assertArrayNotHasKey('hidden', $context['hypervel']);
        $this->assertEquals('bar', $context['hypervel']['foo']);
    }
}
