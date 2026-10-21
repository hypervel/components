<?php

declare(strict_types=1);

use Hypervel\Support\Fluent;

use function PHPStan\Testing\assertType;

/** @var Fluent<string, int> $fluent */
$fluent = new Fluent(['count' => 1]);

assertType('array<string, int>', $fluent->all());
assertType('array<mixed>', $fluent->all('count'));
assertType('array<mixed>', $fluent->all(['count', 'total']));
