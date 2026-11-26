<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Macros;

use Hypervel\Support\Collection;

const LOCAL_VALUE = 'local';

function strlen(string $value): string
{
    return $value;
}

// A rebound closure keeps the names, __NAMESPACE__ and __CLASS__ of where it is written, while self:: follows the binding.
Collection::macro('probeLexical', fn () => [strlen('abc'), LOCAL_VALUE, __NAMESPACE__, self::class]);
Collection::macro('probeLexicalClass', fn () => __CLASS__);
