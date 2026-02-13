<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pool\Stub;

use Hypervel\Pool\Frequency;

class FrequencyStub extends Frequency
{
    public function setBeginTime(int $time): void
    {
        $this->beginTime = $time;
    }

    public function setHits(array $hits): void
    {
        $this->hits = $hits;
    }

    public function getHits(): array
    {
        return $this->hits;
    }
}
