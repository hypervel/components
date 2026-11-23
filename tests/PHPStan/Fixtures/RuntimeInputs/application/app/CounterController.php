<?php

declare(strict_types=1);

namespace RuntimeInputs;

class CounterController
{
    private int $hits = 0;

    public function index(): int
    {
        return ++$this->hits;
    }
}
