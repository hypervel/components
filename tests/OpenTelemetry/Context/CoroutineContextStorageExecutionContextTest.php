<?php

declare(strict_types=1);

namespace Hypervel\Tests\OpenTelemetry\Context;

use Hypervel\OpenTelemetry\Context\CoroutineContextStorage;
use Hypervel\Tests\TestCase;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ContextKeyInterface;
use OpenTelemetry\Context\ContextStorageInterface;
use OpenTelemetry\Context\ExecutionContextAwareInterface;
use OpenTelemetry\Context\ScopeInterface;

class CoroutineContextStorageExecutionContextTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    private ContextStorageInterface&ExecutionContextAwareInterface $previousStorage;

    private CoroutineContextStorage $storage;

    private ContextInterface $baseContext;

    /** @var ContextKeyInterface<string> */
    private ContextKeyInterface $key;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousStorage = Context::storage();
        $this->key = Context::createKey('hypervel.opentelemetry.execution-context-test');
        $this->baseContext = Context::getRoot()->with($this->key, 'base');
        $this->storage = new CoroutineContextStorage($this->baseContext);
        Context::setStorage($this->storage);
    }

    protected function tearDown(): void
    {
        Context::setStorage($this->previousStorage);

        parent::tearDown();
    }

    public function testForkSwitchAndDestroyManageIndependentFallbackStates(): void
    {
        $mainScope = $this->storage->attach($this->context('main'));
        $this->storage->fork('child');
        $newerMainScope = $this->storage->attach($this->context('newer-main'));

        $this->storage->switch('child');

        $this->assertSame('main', $this->storage->current()->get($this->key));
        $this->assertNull($this->storage->scope());

        $childScope = $this->storage->attach($this->context('child'));
        $this->storage->switch('missing');

        $this->assertSame('newer-main', $this->storage->current()->get($this->key));
        $this->assertSame($newerMainScope, $this->storage->scope());
        $this->assertSame(ScopeInterface::INACTIVE, $childScope->detach());

        $this->storage->destroy('child');
        $this->storage->switch('child');

        $this->assertSame('newer-main', $this->storage->current()->get($this->key));
        $this->assertSame(0, $newerMainScope->detach());
        $this->assertSame(0, $mainScope->detach());
        $this->assertSame($this->baseContext, $this->storage->current());
    }

    public function testDestroyDoesNotChangeTheCurrentlySelectedExecutionUnitUntilTheNextSwitch(): void
    {
        $this->storage->fork(42);
        $this->storage->switch(42);
        $scope = $this->storage->attach($this->context('child'));

        $this->storage->destroy(42);

        $this->assertSame('child', $this->storage->current()->get($this->key));
        $this->assertSame(0, $scope->detach());
        $this->assertSame('base', $this->storage->current()->get($this->key));

        $this->storage->switch(42);

        $this->assertSame($this->baseContext, $this->storage->current());
    }

    private function context(string $value): ContextInterface
    {
        return $this->baseContext->with($this->key, $value);
    }
}
