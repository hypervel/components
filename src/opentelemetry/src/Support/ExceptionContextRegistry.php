<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Support;

use OpenTelemetry\Context\ContextInterface;
use Throwable;
use WeakMap;

class ExceptionContextRegistry
{
    protected bool $enabled = false;

    protected bool $recorderRegistered = false;

    /** @var null|WeakMap<Throwable, ExceptionContext> */
    protected ?WeakMap $contexts = null;

    /**
     * Enable exact exception-context handoff.
     *
     * Boot-only. The registry remains enabled for the worker lifetime.
     */
    public function enable(): void
    {
        $this->enabled = true;
    }

    /**
     * Mark direct exception recording as registered on the exception handler.
     *
     * Boot-only. The registration persists on the exception handler for the worker lifetime.
     */
    public function markRecorderRegistered(): void
    {
        $this->recorderRegistered = true;
    }

    /**
     * Determine whether direct exception recording is registered.
     */
    public function hasRecorder(): bool
    {
        return $this->recorderRegistered;
    }

    /**
     * Associate an exception with the operation context that observed it.
     */
    public function associate(Throwable $exception, ContextInterface $context, ?string $origin): void
    {
        if (! $this->enabled) {
            return;
        }

        $entry = new ExceptionContext($context, $origin);
        $contexts = $this->contexts ??= new WeakMap;
        $contexts[$exception] = $entry;

        if (method_exists($exception, 'getInnerException')
            && ($innerException = $exception->getInnerException()) instanceof Throwable
            && $innerException !== $exception
        ) {
            $contexts[$innerException] = $entry;
        }
    }

    /**
     * Take the operation context associated with an exception.
     */
    public function take(Throwable $exception): ?ExceptionContext
    {
        if (($entry = $this->takeExact($exception)) !== null) {
            return $entry;
        }

        return ($previous = $exception->getPrevious()) === null
            ? null
            : $this->takeExact($previous);
    }

    /**
     * Take an exact exception-context association.
     */
    protected function takeExact(Throwable $exception): ?ExceptionContext
    {
        $contexts = $this->contexts;

        if ($contexts === null || ! isset($contexts[$exception])) {
            return null;
        }

        $entry = $contexts[$exception];
        unset($contexts[$exception]);

        return $entry;
    }
}
