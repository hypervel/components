<?php

namespace BleedingEdgeCollectionsIntegration;

use Hypervel\Support\Collection;

/** @param Collection<int, int> $ints */
function takeInts(Collection $ints): void
{
}

/** @return Collection<int, string> */
function names(): Collection
{
    $names = collect();
    $names->push('Taylor');

    return $names;
}

// REMOVED: queries() searching collect([1, 2, 3]) for an int. Hypervel keeps Laravel's covariant TValue,
// and PHPStan 2.3.5 does not widen an already inferred covariant TValue to the argument.

function wrongValue(): void
{
    $ints = collect();
    $ints->push('one');
    takeInts($ints);
}
