<?php

declare(strict_types=1);

use Hypervel\Support\Lottery;

use function PHPStan\Testing\assertType;

$lottery = Lottery::odds(1, 2);

assertType('mixed', $lottery->choose());
assertType('list<mixed>', $lottery->choose(2));
