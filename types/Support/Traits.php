<?php

declare(strict_types=1);

use Hypervel\Support\Traits\Conditionable;
use Hypervel\Support\Traits\Localizable;
use Hypervel\Support\UriQueryString;

use function PHPStan\Testing\assertType;

$localizable = new class {
    use Localizable;

    /**
     * Verify the callback return type is preserved.
     */
    public function useWithLocale(): void
    {
        assertType("'foo'", $this->withLocale('en', fn () => 'foo'));
    }
};

$conditionable = function (ConditionableTarget $target, bool $condition): void {
    // A callback without a result gives the instance back.
    assertType('ConditionableTarget', $target->when($condition, function (): void {
    }));
    assertType('1|ConditionableTarget', $target->unless($condition, fn () => 1));
};

class ConditionableTarget
{
    use Conditionable;
}

$interactsWithData = function (UriQueryString $query): void {
    assertType('1|2|Hypervel\Support\UriQueryString', $query->whenEnum('foo', TestIntEnum::class, function ($enum) {
        assertType('TestIntEnum', $enum);

        return 1;
    }, function () {
        return 2;
    }));

    assertType('3|Hypervel\Support\UriQueryString', $query->whenEnum('foo', TestIntEnum::class, function ($enum) {
        return 3;
    }));

    assertType('1|2|Hypervel\Support\UriQueryString', $query->whenHas('foo', function ($value) {
        assertType('mixed', $value);

        return 1;
    }, function () {
        return 2;
    }));

    assertType('3|Hypervel\Support\UriQueryString', $query->whenHas('foo', function ($value) {
        return 3;
    }));

    assertType('1|2|Hypervel\Support\UriQueryString', $query->whenFilled('foo', function ($value) {
        assertType('mixed', $value);

        return 1;
    }, function () {
        return 2;
    }));

    assertType('3|Hypervel\Support\UriQueryString', $query->whenFilled('foo', function ($value) {
        return 3;
    }));

    assertType('1|2|Hypervel\Support\UriQueryString', $query->whenMissing('foo', function ($value) {
        assertType('mixed', $value);

        return 1;
    }, function () {
        return 2;
    }));

    assertType('3|Hypervel\Support\UriQueryString', $query->whenMissing('foo', function ($value) {
        return 3;
    }));

    // A callback without a result gives the instance back.
    assertType('Hypervel\Support\UriQueryString', $query->whenHas('foo', function ($value): void {
    }));
    assertType('Hypervel\Support\UriQueryString', $query->whenEnum('foo', TestIntEnum::class, fn ($enum) => null));
};

enum TestIntEnum: int
{
}
