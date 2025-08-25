<?php

declare(strict_types=1);

use Hypervel\Database\Eloquent\ModelNotFoundException;

use function PHPStan\Testing\assertType;

/** @var ModelNotFoundException<User> $exception */
$exception = new ModelNotFoundException();

assertType('array<int, int|string>', $exception->getIds());
assertType('class-string<User>|null', $exception->getModel());

$exception->setModel(User::class, 1);
$exception->setModel(User::class, [1]);
$exception->setModel(User::class, '1');
$exception->setModel(User::class, ['1']);
