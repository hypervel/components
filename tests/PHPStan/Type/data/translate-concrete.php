<?php

declare(strict_types=1);

namespace TranslateConcrete;

use function PHPStan\Testing\assertType;

function test(?string $key): void
{
    assertType('Hypervel\Translation\Translator', trans());
    assertType('Hypervel\Translation\Translator', trans(null));
    assertType('Hypervel\Translation\Translator', trans(key: null));
    assertType('Hypervel\Translation\Translator', trans(locale: 'en'));
    assertType('Hypervel\Translation\Translator', trans(...[null]));
    assertType('Hypervel\Translation\Translator', trans(...[null, [], 'en']));
    assertType('Hypervel\Translation\Translator', trans(...[]));
    assertType('array|Hypervel\Translation\Translator|string', trans($key));
}
