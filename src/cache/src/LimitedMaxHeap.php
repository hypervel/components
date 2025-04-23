<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use SplMaxHeap;

class LimitedMaxHeap extends SplMaxHeap
{
    public function __construct(protected int $limit)
    {
    }

    public function insert(mixed $value): true
    {
        if ($this->count() < $this->limit) {
            parent::insert($value);
            return true;
        }

        if ($this->compare($value, $this->top()) < 0) {
            $this->extract();
        }

        parent::insert($value);

        return true;
    }
}
