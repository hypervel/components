<?php

declare(strict_types=1);

namespace Hypervel\Tests\OpenTelemetry\Support;

use Hypervel\OpenTelemetry\Support\ExceptionContextRegistry;
use Hypervel\OpenTelemetry\Support\OperationOrigin;
use Hypervel\Tests\TestCase;
use OpenTelemetry\Context\Context;
use RuntimeException;
use Throwable;

class ExceptionContextRegistryTest extends TestCase
{
    public function testDisabledRegistryPerformsNoHandoff(): void
    {
        $registry = new ExceptionContextRegistry;
        $exception = new RuntimeException('Failed');

        $registry->associate($exception, Context::getRoot(), 'request');

        $this->assertNull($registry->take($exception));
    }

    public function testRecorderRegistrationIsIndependentFromContextHandoffEnablement(): void
    {
        $registry = new ExceptionContextRegistry;

        $this->assertFalse($registry->hasRecorder());

        $registry->enable();

        $this->assertFalse($registry->hasRecorder());

        $registry->markRecorderRegistered();

        $this->assertTrue($registry->hasRecorder());
    }

    public function testEnabledRegistryAtomicallyTransfersTheExactContext(): void
    {
        $registry = new ExceptionContextRegistry;
        $registry->enable();
        $exception = new RuntimeException('Failed');
        $context = Context::getRoot();

        $registry->associate($exception, $context, null);

        $handoff = $registry->take($exception);

        $this->assertNotNull($handoff);
        $this->assertSame($context, $handoff->context);
        $this->assertNull($handoff->origin);
        $this->assertNull($registry->take($exception));
    }

    public function testItTransfersTheContextFromOneDirectPreviousException(): void
    {
        $registry = new ExceptionContextRegistry;
        $registry->enable();
        $previous = new RuntimeException('Connection failed');
        $exception = new RuntimeException('Request failed', previous: $previous);
        $context = Context::getRoot();

        $registry->associate($previous, $context, OperationOrigin::REQUEST);

        $handoff = $registry->take($exception);

        $this->assertNotNull($handoff);
        $this->assertSame($context, $handoff->context);
        $this->assertSame(OperationOrigin::REQUEST, $handoff->origin);
        $this->assertNull($registry->take($previous));
    }

    public function testItDoesNotWalkAnArbitraryPreviousExceptionChain(): void
    {
        $registry = new ExceptionContextRegistry;
        $registry->enable();
        $originatingException = new RuntimeException('Connection failed');
        $intermediateException = new RuntimeException('Request failed', previous: $originatingException);
        $reportedException = new RuntimeException('Application request failed', previous: $intermediateException);
        $context = Context::getRoot();

        $registry->associate($originatingException, $context, OperationOrigin::REQUEST);

        $this->assertNull($registry->take($reportedException));
        $handoff = $registry->take($originatingException);

        $this->assertNotNull($handoff);
        $this->assertSame($context, $handoff->context);
        $this->assertSame(OperationOrigin::REQUEST, $handoff->origin);
    }

    public function testAssociatesTheFrameworkRecognizedInnerException(): void
    {
        $registry = new ExceptionContextRegistry;
        $registry->enable();
        $innerException = new RuntimeException('Inner');
        $exception = new ExceptionContextRegistryWrapperException($innerException);
        $context = Context::getRoot();

        $registry->associate($exception, $context, OperationOrigin::REQUEST);

        $handoff = $registry->take($innerException);

        $this->assertNotNull($handoff);
        $this->assertSame($context, $handoff->context);
        $this->assertSame(OperationOrigin::REQUEST, $handoff->origin);
    }
}

class ExceptionContextRegistryWrapperException extends RuntimeException
{
    /**
     * Create a wrapper with the same mapping shape as streamed responses.
     */
    public function __construct(private Throwable $innerException)
    {
        parent::__construct($innerException->getMessage());
    }

    /**
     * Return the framework-recognized inner exception.
     */
    public function getInnerException(): Throwable
    {
        return $this->innerException;
    }
}
