<?php

namespace ArrayableIntegration;

use Hypervel\Contracts\Support\Arrayable;

/** @param Arrayable<array<string, int>> $value */
function integers(Arrayable $value): void
{
}

/** @param Arrayable<array<string, string>> $value */
function incompatible(Arrayable $value): void
{
    integers($value);
}

// REMOVED: invalidKeys() and invalidVariance(); they cover upstream's Laravel 13 Arrayable<TKey, TValue> form, which Hypervel's contract does not accept.
