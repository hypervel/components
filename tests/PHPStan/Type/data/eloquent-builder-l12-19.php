<?php

declare(strict_types=1);

namespace EloquentBuilder1215;

use Hypervel\Database\Eloquent\Attributes\UseEloquentBuilder;
use Hypervel\Database\Eloquent\Builder;
use function PHPStan\Testing\assertType;

#[UseEloquentBuilder(CustomBuilder::class)]
class A extends \Hypervel\Database\Eloquent\Model {}

#[UseEloquentBuilder(CustomBuilder::class)]
class B extends \Hypervel\Database\Eloquent\Model
{
    public function newEloquentBuilder($query): AnotherCustomBuilder
    {
        return new AnotherCustomBuilder($query);
    }
}

#[UseEloquentBuilder('EloquentBuilder1215\CustomBuilder')]
class C extends \Hypervel\Database\Eloquent\Model {}

#[UseEloquentBuilder('\EloquentBuilder1215\CustomBuilder')]
class D extends \Hypervel\Database\Eloquent\Model {}

#[UseEloquentBuilder(builderClass: CustomBuilder::class)]
class E extends \Hypervel\Database\Eloquent\Model {}

#[UseEloquentBuilder('EloquentBuilder1215\MissingBuilder')]
class F extends \Hypervel\Database\Eloquent\Model {}

#[UseEloquentBuilder(BuilderNames::CUSTOM)]
class G extends \Hypervel\Database\Eloquent\Model {}

#[UseEloquentBuilder(self::BUILDER)]
class H extends \Hypervel\Database\Eloquent\Model
{
    public const string BUILDER = CustomBuilder::class;
}

class BuilderNames
{
    public const string CUSTOM = CustomBuilder::class;
}

/** @extends Builder<A> */
class CustomBuilder extends \Hypervel\Database\Eloquent\Builder {}

/** @extends Builder<B> */
class AnotherCustomBuilder extends \Hypervel\Database\Eloquent\Builder {}

function test(): void
{
    assertType('EloquentBuilder1215\CustomBuilder', A::query());
    assertType('EloquentBuilder1215\AnotherCustomBuilder', B::query());
    assertType('EloquentBuilder1215\CustomBuilder', C::query());
    assertType('EloquentBuilder1215\CustomBuilder', D::query());
    assertType('EloquentBuilder1215\CustomBuilder', E::query());
    assertType('Hypervel\Database\Eloquent\Builder<EloquentBuilder1215\F>', F::query());

    // Unlike upstream, which reads a class constant's class as the builder, the constant's value names it, as
    // instantiating the attribute reads it.
    assertType('EloquentBuilder1215\CustomBuilder', G::query());
    assertType('EloquentBuilder1215\CustomBuilder', H::query());
}
