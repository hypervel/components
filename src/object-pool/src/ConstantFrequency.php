<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool;

use Hyperf\Coordinator\Timer;

class ConstantFrequency implements LowFrequencyInterface
{
    protected Timer $timer;

    protected ?int $timerId = null;

    protected int $interval = 10;

    protected float $recycleRatio = 0.2;

    public function __construct(protected ?ObjectPool $pool = null)
    {
        $this->timer = new Timer();
        if ($pool) {
            $this->timerId = $this->timer->tick(
                $this->interval,
                fn () => $this->recycleObjects()
            );
        }
    }

    public function __destruct()
    {
        $this->clear();
    }

    public function clear()
    {
        if ($this->timerId) {
            $this->timer->clear($this->timerId);
        }
        $this->timerId = null;
    }

    public function isLowFrequency(): bool
    {
        return false;
    }

    protected function recycleObjects(): void
    {
        $recycleCount = floor($this->recycleRatio * $this->pool->getObjectNumberInPool());
        $recycleCount = $recycleCount > 1 ?: 1;

        for ($i = 0; $i <= $recycleCount; ++$i) {
            $this->pool->flushOne();
        }
    }
}
