<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use SplMaxHeap;

class LimitedMaxHeap extends SplMaxHeap
{
    public function __construct(protected int $limit)
    {
    }

    public function insert(mixed $value): bool
    {
        if ($this->count() < $this->limit) {
            return parent::insert($value);
        }

        if ($this->compare($value, $this->top()) < 0) {
            $this->extract();

            return parent::insert($value);
        }

        return false;
    }
}
