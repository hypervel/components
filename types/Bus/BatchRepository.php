<?php

declare(strict_types=1);

use Hypervel\Bus\BatchRepository;

use function PHPStan\Testing\assertType;

/** @var BatchRepository $repository */
$repository = resolve(BatchRepository::class);

assertType("'foo'", $repository->transaction(fn () => 'foo'));
