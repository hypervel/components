<?php

declare(strict_types=1);

namespace App;

use Hypervel\Scout\Searchable;

/**
 * A model whose trait declares `@mixin Model`, which must not give it every property.
 */
class SearchableUser extends User
{
    use Searchable;

    protected ?string $table = 'users';
}
