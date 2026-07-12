<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use InvalidArgumentException;
use SplMaxHeap;

class LimitedMaxHeap extends SplMaxHeap
{
    public function __construct(protected int $limit)
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('Heap limit must be at least 1.');
        }
    }

    public function insert(mixed $value): true
    {
        if ($this->count() < $this->limit) {
            parent::insert($value);

            return true;
        }

        if ($this->compare($value, $this->top()) < 0) {
            $this->extract();
            parent::insert($value);
        }

        return true;
    }
}
