<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Concerns;

use Hypervel\View\ViewName;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;

/** @internal */
trait CollectsMailViews
{
    /**
     * Get the views a literal mail view argument names.
     *
     * Upstream reads only a string. The mailer also takes an array, which names the HTML and
     * plain-text views at 0 and 1, or under the html and text keys. Its raw key holds text.
     *
     * @return null|list<string>
     */
    private function getMailViews(?Expr $view, Scope $scope): ?array
    {
        if ($view instanceof String_) {
            return [ViewName::normalize($view->value)];
        }

        if (! $view instanceof Array_) {
            return null;
        }

        $views = [];

        foreach ($scope->getType($view)->getConstantArrays() as $array) {
            $keys = $array->hasOffsetValueType(new ConstantIntegerType(0))->yes()
                ? [new ConstantIntegerType(0), new ConstantIntegerType(1)]
                : [new ConstantStringType('html'), new ConstantStringType('text')];

            foreach ($keys as $key) {
                foreach ($array->getOffsetValueType($key)->getConstantStrings() as $name) {
                    $views[] = ViewName::normalize($name->getValue());
                }
            }
        }

        return $views === [] ? null : $views;
    }
}
