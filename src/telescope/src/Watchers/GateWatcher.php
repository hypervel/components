<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Watchers;

use Hypervel\Auth\Access\Events\GateEvaluated;
use Hypervel\Auth\Access\Response;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use Hypervel\Telescope\FormatModel;
use Hypervel\Telescope\IncomingEntry;
use Hypervel\Telescope\Telescope;

class GateWatcher extends Watcher
{
    use FetchesStackTrace;

    /**
     * Register the watcher.
     */
    public function register(Application $app): void
    {
        $app->make(Dispatcher::class)
            ->listen(GateEvaluated::class, [$this, 'handleGateEvaluated']);
    }

    /**
     * Handle the GateEvaluated event.
     */
    public function handleGateEvaluated(GateEvaluated $event): void
    {
        $this->recordGateCheck($event->user, $event->ability, $event->result, $event->arguments);
    }

    /**
     * Record a gate check.
     */
    public function recordGateCheck(mixed $user, string $ability, mixed $result, array $arguments): mixed
    {
        if (! Telescope::isRecording() || $this->shouldIgnore($ability)) {
            return $result;
        }

        $caller = $this->getCallerFromStackTrace([0, 1]);

        Telescope::recordGate(IncomingEntry::make([
            'ability' => $ability,
            'result' => $this->gateResult($result),
            'message' => $this->gateMessage($result),
            'arguments' => $this->formatArguments($arguments),
            'file' => $caller['file'] ?? null,
            'line' => $caller['line'] ?? null,
        ]));

        return $result;
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
    private function gateResult(bool|Response|null $result): string
    {
        if ($result instanceof Response) {
            return $result->allowed() ? 'allowed' : 'denied';
        }

        return $result ? 'allowed' : 'denied';
    }

    /**
     * Get the message returned by the gate.
     */
    private function gateMessage(mixed $result): ?string
    {
        if ($result instanceof Response) {
            return $result->message();
        }

        return null;
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
