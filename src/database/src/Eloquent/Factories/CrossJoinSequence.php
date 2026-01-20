<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Factories;

use Hypervel\Support\Arr;

class CrossJoinSequence extends Sequence
{
    /**
     * Create a new cross join sequence instance.
     *
     * @param array<string, mixed> ...$sequences
     */
    public function __construct(array ...$sequences)
    {
        $crossJoined = array_map(
            fn ($a) => array_merge(...$a),
            Arr::crossJoin(...$sequences),
        );

        parent::__construct(...$crossJoined);
    }
}
