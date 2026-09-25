<?php

declare(strict_types=1);

use Hypervel\Support\Testing\Fakes\BatchRepositoryFake;

use function PHPStan\Testing\assertType;

/** @var BatchRepositoryFake $repository */
$repository = resolve(BatchRepositoryFake::class);

assertType("'foo'", $repository->transaction(fn () => 'foo'));
