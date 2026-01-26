<?php

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

assertType(
    'Hypervel\Contracts\Database\Eloquent\CastsAttributes<Hypervel\Support\Stringable, string|Stringable>',
    \Hypervel\Database\Eloquent\Casts\AsStringable::castUsing([]),
);
