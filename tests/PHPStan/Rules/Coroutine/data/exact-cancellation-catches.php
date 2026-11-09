<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Coroutine\Data;

use Hypervel\Coroutine\Coroutine;
use Hypervel\Coroutine\WaitGroup;
use Hypervel\Engine\Channel;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

class ExactCancellationCatches
{
    public function handlesCarriedCancellationAsFailure(): int
    {
        try {
            $this->work();
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->report($exception);

            return 0;
        }

        return 1;
    }

    public function guardsWithoutLeaving(): int
    {
        try {
            $this->work();
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if (Coroutine::causedByCancellation($exception)) {
                $this->report($exception);
            }

            return 0;
        }

        return 1;
    }

    public function guardsAnotherException(Throwable $previous): int
    {
        try {
            $this->work();
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if (Coroutine::causedByCancellation($previous)) {
                throw $exception;
            }

            return 0;
        }

        return 1;
    }

    public function waitsAndWorks(Channel $channel): mixed
    {
        try {
            $value = $channel->pop(1.0);
            $this->work();

            return $value;
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable) {
            return null;
        }
    }

    public function waitsOnOverriddenChannel(PollingChannel $channel): mixed
    {
        try {
            return $channel->pop(1.0);
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable) {
            return null;
        }
    }

    public function rethrowsCarriedCancellation(): void
    {
        try {
            $this->work();
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if (Coroutine::causedByCancellation($exception)) {
                throw $exception;
            }

            $this->report($exception);
        }
    }

    public function onlyRethrows(): void
    {
        try {
            $this->work();
        } catch (CanceledException $exception) {
            $this->cleanUp();

            throw $exception;
        } catch (Throwable $exception) {
            throw $exception;
        }
    }

    public function separatesBranches(): void
    {
        try {
            $this->work();
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if (Coroutine::causedByCancellation($exception)) {
                $this->cleanUp();
            } else {
                $this->report($exception);
            }
        }
    }

    public function handlesOnlyOrdinaryFailures(): void
    {
        try {
            $this->work();
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if (! Coroutine::causedByCancellation($exception)) {
                $this->report($exception);
            }
        }
    }

    /**
     * @param list<int> $coroutineIds
     */
    public function waitsNatively(Channel $channel, WaitGroup $waitGroup, array $coroutineIds): mixed
    {
        try {
            $waitGroup->wait(1.0);
            Coroutine::join($coroutineIds, 1.0);
            $results = new Channel(1);
            $later = $this->work(...);

            return $channel->pop(1.0);
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable) {
            return null;
        }
    }

    public function waitsOnConstructedChannel(): mixed
    {
        try {
            $channel = new Channel(1);

            return $channel->pop(1.0);
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable) {
            return null;
        }
    }

    public function waitsOnReplacedChannel(Channel $channel): mixed
    {
        try {
            $channel = $this->replacement();

            return $channel->pop(1.0);
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable) {
            return null;
        }
    }

    public function waitsOnConditionallyConstructedChannel(PollingChannel $channel, bool $replace): mixed
    {
        try {
            if ($replace) {
                $channel = new Channel(1);
            }

            return $channel->pop(1.0);
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable) {
            return null;
        }
    }

    public function keepsCancellationForLater(): ?Throwable
    {
        $cancellation = null;

        try {
            $this->work();
        } catch (CanceledException $exception) {
            $cancellation = $exception;
        } catch (Throwable $exception) {
            $this->report($exception);
        }

        return $cancellation;
    }

    public function endsOnCancellation(): void
    {
        try {
            $this->work();
        } catch (CanceledException) {
            return;
        } catch (Throwable $exception) {
            $this->report($exception);
        }
    }

    public function hasNoBroadCatch(): void
    {
        try {
            $this->work();
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            $this->report($exception);
        }
    }

    private function work(): void
    {
    }

    private function cleanUp(): void
    {
    }

    private function replacement(): Channel
    {
        return new PollingChannel(1);
    }

    private function report(Throwable $exception): void
    {
    }
}

/**
 * @extends Channel<mixed>
 */
class PollingChannel extends Channel
{
    public function pop(float $timeout = -1): mixed
    {
        return parent::pop($timeout);
    }
}
