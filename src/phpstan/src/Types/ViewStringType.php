<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Types;

use Hypervel\View\Factory;
use PHPStan\Type\AcceptsResult;
use PHPStan\Type\CompoundType;
use PHPStan\Type\IsSuperTypeOfResult;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeTraverser;
use PHPStan\Type\VerbosityLevel;

use function count;
use function view;

/**
 * The custom 'view-string' type class. It's a subset of the string type. Every string that passes the
 * view()->exists($string) test is a valid view-string type.
 */
class ViewStringType extends StringType
{
    /**
     * Describe the type.
     */
    public function describe(VerbosityLevel $level): string
    {
        return 'view-string';
    }

    /**
     * Determine whether the type accepts the given type.
     */
    public function accepts(Type $type, bool $strictTypes): AcceptsResult
    {
        if ($type instanceof CompoundType) {
            return $type->isAcceptedBy($this, $strictTypes);
        }

        $constantStrings = $type->getConstantStrings();

        if (count($constantStrings) === 1) {
            /** @var Factory $view */
            $view = view();

            return AcceptsResult::createFromBoolean($view->exists($constantStrings[0]->getValue()));
        }

        if ($type instanceof self) {
            return AcceptsResult::createYes();
        }

        if ($type->isString()->yes()) {
            return AcceptsResult::createMaybe();
        }

        return AcceptsResult::createNo();
    }

    /**
     * Determine whether the type is a supertype of the given type.
     */
    public function isSuperTypeOf(Type $type): IsSuperTypeOfResult
    {
        $constantStrings = $type->getConstantStrings();

        if (count($constantStrings) === 1) {
            /** @var Factory $view */
            $view = view();

            return IsSuperTypeOfResult::createFromBoolean($view->exists($constantStrings[0]->getValue()));
        }

        if ($type instanceof self) {
            return IsSuperTypeOfResult::createYes();
        }

        if ($type->isString()->yes()) {
            return IsSuperTypeOfResult::createMaybe();
        }

        if ($type instanceof CompoundType) {
            return $type->isSubTypeOf($this);
        }

        return IsSuperTypeOfResult::createNo();
    }

    /**
     * Determine whether a view-string type occurs in the type, such as in a union or a callable signature.
     */
    public static function occursIn(Type $type): bool
    {
        $found = false;

        TypeTraverser::map($type, static function (Type $type, callable $traverse) use (&$found): Type {
            if ($type instanceof self) {
                $found = true;
            }

            return $found ? $type : $traverse($type);
        });

        return $found;
    }

    /**
     * Restore the type from its exported state.
     *
     * @param mixed[] $properties
     */
    public static function __set_state(array $properties): Type
    {
        return new self;
    }
}
