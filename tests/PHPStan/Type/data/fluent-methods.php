<?php

namespace FluentMethods;

use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Fluent;

use function PHPStan\Testing\assertType;

/**
 * @method string declaredByTag()
 *
 * @extends Fluent<string, mixed>
 */
class CustomFluent extends Fluent
{
}

/** @param Fluent<string, int> $fluent */
function test(Fluent $fluent, CustomFluent $customFluent, Blueprint $table): void
{
    assertType('Hypervel\Support\Fluent<string, int>', $fluent->anything());
    assertType('Hypervel\Support\Fluent<string, int>', $fluent->anything(5)->somethingElse());

    assertType('FluentMethods\CustomFluent', $customFluent->anything(5));
    assertType('string', $customFluent->declaredByTag());

    // Schema definitions keep the methods declared by their own @method tags.
    assertType('Hypervel\Database\Schema\ColumnDefinition', $table->string('name')->nullable());
    assertType('Hypervel\Database\Schema\ColumnDefinition', $table->string('name')->anything());

    // Macros take precedence.
    assertType('array<string, int>', $fluent->fluentMacro());
    assertType('array<string, mixed>', $customFluent->fluentMacro());
}
