<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Instrumentation;

use Hypervel\Contracts\Events\Dispatcher;
use OpenTelemetry\API\Trace\Span;

class EventInstrumentation extends AbstractInstrumentation
{
    /**
     * Create event instrumentation.
     */
    public function __construct(protected Dispatcher $events)
    {
    }

    /**
     * Register exact passive event observers.
     */
    protected function registerInstrumentation(): void
    {
        /** @var list<string> $events */
        $events = $this->options->get('events');

        if ($events === []) {
            return;
        }

        $this->events->observe($events, static function (string $event): void {
            $span = Span::getCurrent();

            if ($span->isRecording()) {
                $span->addEvent($event);
            }
        });
    }
}
