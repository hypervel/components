<?php

declare(strict_types=1);

use Hypervel\Support\Stringable;

use function PHPStan\Testing\assertType;

/** @var int $flags */
$string = new Stringable('a b');

assertType('Hypervel\Support\Collection<int, string>', $string->split('/ /'));
assertType('Hypervel\Support\Collection<int, string>', $string->split('/( )/', -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE));
assertType('Hypervel\Support\Collection<int, string>', $string->split(2));
assertType('Hypervel\Support\Collection<int, array{string, int<0, max>}|string>', $string->split('/ /', -1, PREG_SPLIT_OFFSET_CAPTURE));
assertType('Hypervel\Support\Collection<int, array{string, int<0, max>}|string>', $string->split('/ /', -1, $flags));
