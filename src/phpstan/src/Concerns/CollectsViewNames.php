<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Concerns;

use Hypervel\View\ViewName;
use PhpParser\Node\Expr;
use PHPStan\Analyser\Scope;
use PHPStan\Type\Constant\ConstantStringType;

use function array_map;

/** @internal */
trait CollectsViewNames
{
    /**
     * Get the views a view argument names, one for each constant string its type allows.
     *
     * Upstream reads only a string literal, so a view named through a constant, or each
     * name a union allows, was not collected.
     *
     * @return null|list<string>
     */
    private function getViewNames(?Expr $view, Scope $scope): ?array
    {
        if ($view === null) {
            return null;
        }

        $names = array_map(
            static fn (ConstantStringType $name): string => ViewName::normalize($name->getValue()),
            $scope->getType($view)->getConstantStrings(),
        );

        return $names === [] ? null : $names;
    }
}
