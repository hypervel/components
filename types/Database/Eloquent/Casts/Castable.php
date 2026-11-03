<?php

declare(strict_types=1);

use Hypervel\Database\Eloquent\Casts\AsFluent;
use Hypervel\Support\Fluent;

use function PHPStan\Testing\assertType;

assertType(
    'Hypervel\Contracts\Database\Eloquent\CastsAttributes<Hypervel\Database\Eloquent\Casts\ArrayObject<(int|string), mixed>, iterable>',
    \Hypervel\Database\Eloquent\Casts\AsArrayObject::castUsing([]),
);

assertType(
    'Hypervel\Contracts\Database\Eloquent\CastsAttributes<Hypervel\Support\Collection<(int|string), mixed>, iterable>',
    \Hypervel\Database\Eloquent\Casts\AsCollection::castUsing([]),
);

assertType(
    'Hypervel\Contracts\Database\Eloquent\CastsAttributes<Hypervel\Database\Eloquent\Casts\ArrayObject<(int|string), mixed>, iterable>',
    \Hypervel\Database\Eloquent\Casts\AsEncryptedArrayObject::castUsing([]),
);

assertType(
    'Hypervel\Contracts\Database\Eloquent\CastsAttributes<Hypervel\Support\Collection<(int|string), mixed>, iterable>',
    \Hypervel\Database\Eloquent\Casts\AsEncryptedCollection::castUsing([]),
);

assertType(
    'Hypervel\Contracts\Database\Eloquent\CastsAttributes<Hypervel\Database\Eloquent\Casts\ArrayObject<(int|string), UserType>, iterable<UserType>>',
    \Hypervel\Database\Eloquent\Casts\AsEnumArrayObject::castUsing([\UserType::class]),
);

assertType(
    'Hypervel\Contracts\Database\Eloquent\CastsAttributes<Hypervel\Support\Collection<(int|string), UserType>, iterable<UserType>>',
    \Hypervel\Database\Eloquent\Casts\AsEnumCollection::castUsing([\UserType::class]),
);

/** @var User $user */
$fluentCast = AsFluent::castUsing([]);

assertType('Hypervel\Contracts\Database\Eloquent\CastsAttributes<Hypervel\Support\Fluent, array|object>', $fluentCast);

$fluentCast->set($user, 'options', ['theme' => 'dark'], $user->getAttributes());
$fluentCast->set($user, 'options', new Fluent(['theme' => 'dark']), $user->getAttributes());

assertType(
    'Hypervel\Contracts\Database\Eloquent\CastsAttributes<Hypervel\Support\Stringable, string|Stringable>',
    \Hypervel\Database\Eloquent\Casts\AsStringable::castUsing([]),
);
