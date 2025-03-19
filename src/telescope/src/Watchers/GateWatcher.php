<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Watchers;

use Hyperf\Collection\Collection;
use Hyperf\Database\Model\Model;
use Hyperf\Stringable\Str;
use Hypervel\Auth\Access\Events\GateEvaluated;
use Hypervel\Auth\Access\Response;
use Hypervel\Telescope\FormatModel;
use Hypervel\Telescope\IncomingEntry;
use Hypervel\Telescope\Telescope;
use Hypervel\Telescope\Watchers\Traits\FetchesStackTrace;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

class GateWatcher extends Watcher
{
    use FetchesStackTrace;

    /**
     * Register the watcher.
     */
    public function register(ContainerInterface $app): void
    {
        $app->get(EventDispatcherInterface::class)
            ->listen(GateEvaluated::class, [$this, 'recordGateCheck']);
    }

    /**
     * Record a gate check.
     */
    public function recordGateCheck(GateEvaluated $event): void
    {
        if (! Telescope::isRecording() || $this->shouldIgnore($event->ability)) {
            return;
        }

        $caller = $this->getCallerFromStackTrace();

        Telescope::recordGate(IncomingEntry::make([
            'ability' => $event->ability,
            'result' => $this->gateResult($event->result),
            'arguments' => $this->formatArguments($event->arguments),
            'file' => $caller['file'] ?? null,
            'line' => $caller['line'] ?? null,
        ]));
    }

    /**
     * Determine if the ability should be ignored.
     */
    private function shouldIgnore(string $ability): bool
    {
        return Str::is($this->options['ignore_abilities'] ?? [], $ability);
    }

    /**
     * Determine if the gate result is denied or allowed.
     */
    private function gateResult(bool|Response $result): string
    {
        if ($result instanceof Response) {
            return $result->allowed() ? 'allowed' : 'denied';
        }

        return $result ? 'allowed' : 'denied';
    }

    /**
     * Format the given arguments.
     */
    private function formatArguments(array $arguments): array
    {
        return Collection::make($arguments)->map(function ($argument) {
            if (is_object($argument) && method_exists($argument, 'formatForTelescope')) {
                return $argument->formatForTelescope();
            }

            if ($argument instanceof Model) {
                return FormatModel::given($argument);
            }

            return $argument;
        })->toArray();
    }
}
