<?php

declare(strict_types=1);

use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Support\Collection;
use Hypervel\Support\LazyCollection;

use function PHPStan\Testing\assertType;

/**
 * @implements Arrayable<array{id: int, name: string, tags: array<string, string>}>
 */
class ShapedArrayableResponse implements Arrayable
{
    /**
     * Get the response as an array.
     */
    public function toArray(): array
    {
        return [
            'id' => 1,
            'name' => 'Taylor',
            'tags' => ['framework' => 'Hypervel'],
        ];
    }
}

assertType('array{id: int, name: string, tags: array<string, string>}', (new ShapedArrayableResponse)->toArray());
assertType('Hypervel\Support\Collection<string, array<string, string>|int|string>', collect(new ShapedArrayableResponse));
assertType('Hypervel\Support\Collection<string, array<string, string>|int|string>', Collection::make(new ShapedArrayableResponse));
assertType('Hypervel\Support\LazyCollection<string, array<string, string>|int|string>', LazyCollection::make(new ShapedArrayableResponse));
