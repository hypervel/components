<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Integration;

use Exception;
use Hypervel\Sentry\Integration\HypervelContextIntegration;
use Hypervel\Support\Facades\Context;
use Hypervel\Tests\Sentry\TestCase;
use Sentry\EventType;

use function Sentry\captureException;

class HypervelContextIntegrationTest extends TestCase
{
    public function testHypervelContextIntegrationIsRegistered(): void
    {
        $integration = $this->getSentryHubFromContainer()->getIntegration(HypervelContextIntegration::class);

        $this->assertInstanceOf(HypervelContextIntegration::class, $integration);
    }

    public function testExceptionIsCapturedWithHypervelContext(): void
    {
        $this->setupTestContext();

        captureException(new Exception('Context test'));

        $event = $this->getLastSentryEvent();

        $this->assertNotNull($event);
        $this->assertEquals($event->getType(), EventType::event());
        $this->assertContextIsCaptured($event->getContexts());
    }

    public function testExceptionIsCapturedWithoutHypervelContextIfEmpty(): void
    {
        captureException(new Exception('Context test'));

        $event = $this->getLastSentryEvent();

        $this->assertNotNull($event);
        $this->assertEquals($event->getType(), EventType::event());
        $this->assertArrayNotHasKey('hypervel', $event->getContexts());
    }

    public function testExceptionIsCapturedWithoutHypervelContextIfOnlyHidden(): void
    {
        Context::addHidden('hidden', 'value');

        captureException(new Exception('Context test'));

        $event = $this->getLastSentryEvent();

        $this->assertNotNull($event);
        $this->assertEquals($event->getType(), EventType::event());
        $this->assertArrayNotHasKey('hypervel', $event->getContexts());
    }

    public function testTransactionIsCapturedWithHypervelContext(): void
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

    /**
     * Add visible and hidden values to the context.
     */
    private function setupTestContext(): void
    {
        Context::flush();
        Context::add('foo', 'bar');
        Context::addHidden('hidden', 'value');
    }

    /**
     * Assert that only the visible context values were captured.
     */
    private function assertContextIsCaptured(array $context): void
    {
        $this->assertArrayHasKey('hypervel', $context);
        $this->assertArrayHasKey('foo', $context['hypervel']);
        $this->assertArrayNotHasKey('hidden', $context['hypervel']);
        $this->assertEquals('bar', $context['hypervel']['foo']);
    }
}
