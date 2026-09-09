<?php

declare(strict_types=1);

use Hypervel\Events\Dispatcher;
use Hypervel\Log\Context\Repository;

use function PHPStan\Testing\assertType;

$repository = new Repository(new Dispatcher);

$value = $repository->scope(fn (): int => random_int(-100, 100));
assertType('int<-100, 100>', $value);

$void = $repository->scope(function (): void { // @phpstan-ignore method.void
});
assertType('null', $void);

$repository->dehydrating(fn (Repository $context): Repository => $context->add('dehydrated', true));
$repository->hydrated(fn (Repository $context): Repository => $context->add('hydrated', true));
