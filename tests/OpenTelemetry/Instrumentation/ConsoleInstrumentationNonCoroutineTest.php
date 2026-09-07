<?php

declare(strict_types=1);

namespace Hypervel\Tests\OpenTelemetry\Instrumentation;

use ArrayObject;
use Hypervel\Console\Command;
use Hypervel\Console\Events\AfterExecute;
use Hypervel\Console\Events\BeforeHandle;
use Hypervel\Events\Dispatcher;
use Hypervel\OpenTelemetry\Context\CoroutineContextStorage;
use Hypervel\OpenTelemetry\Instrumentation\ConsoleInstrumentation;
use Hypervel\OpenTelemetry\Support\ExceptionContextRegistry;
use Hypervel\OpenTelemetry\Support\LogContextScopeFactory;
use Hypervel\OpenTelemetry\Support\OperationOrigin;
use Hypervel\OpenTelemetry\Support\ProcessIdentity;
use Hypervel\Tests\TestCase;
use Mockery as m;
use OpenTelemetry\API\Common\Time\ClockInterface;
use OpenTelemetry\API\Metrics\Noop\NoopMeterProvider;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextStorageInterface;
use OpenTelemetry\Context\ExecutionContextAwareInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Symfony\Component\Console\Input\ArrayInput;

class ConsoleInstrumentationNonCoroutineTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    private ContextStorageInterface&ExecutionContextAwareInterface $previousStorage;

    private Dispatcher $events;

    private InMemoryExporter $spanExporter;

    private TracerProvider $tracerProvider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousStorage = Context::storage();
        Context::setStorage(new CoroutineContextStorage(Context::getRoot()));
        $this->events = new Dispatcher;
        $this->spanExporter = new InMemoryExporter(new ArrayObject);
        $this->tracerProvider = TracerProvider::builder()
            ->addSpanProcessor(new SimpleSpanProcessor($this->spanExporter))
            ->build();
    }

    protected function tearDown(): void
    {
        $this->tracerProvider->shutdown();
        Context::setStorage($this->previousStorage);

        parent::tearDown();
    }

    public function testCommandSpanUsesAndClearsTheNonCoroutineContextStack(): void
    {
        $logContextScopes = m::mock(LogContextScopeFactory::class);
        $logContextScopes->shouldReceive('activate')->once()->andReturnNull();
        $instrumentation = new ConsoleInstrumentation(
            $this->events,
            $this->tracerProvider,
            new NoopMeterProvider,
            new ConsoleInstrumentationNonCoroutineClock,
            new ExceptionContextRegistry,
            new OperationOrigin,
            ProcessIdentity::cli(),
            $logContextScopes,
        );
        $instrumentation->register([
            'traces' => true,
            'commands' => ['*'],
            'except' => [],
            'metrics' => false,
        ]);
        $command = new ConsoleInstrumentationNonCoroutineCommand('package:discover');
        $input = new ArrayInput([]);

        $this->events->dispatch(new BeforeHandle($command, $input));
        $this->assertTrue(Span::getCurrent()->getContext()->isValid());
        $this->events->dispatch(new AfterExecute($command, null, $input, 0));

        $this->assertFalse(Span::getCurrent()->getContext()->isValid());
        $this->assertCount(1, $this->spanExporter->getSpans());
        $this->assertSame('package:discover', $this->spanExporter->getSpans()[0]->getName());
    }
}

class ConsoleInstrumentationNonCoroutineClock implements ClockInterface
{
    private int $timestamp = 0;

    /**
     * Return the next test timestamp.
     */
    public function now(): int
    {
        return ++$this->timestamp;
    }
}

class ConsoleInstrumentationNonCoroutineCommand extends Command
{
}
