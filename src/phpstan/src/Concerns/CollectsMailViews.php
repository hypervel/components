<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Concerns;

use Hypervel\View\ViewName;
use PhpParser\Node\Expr;
use PHPStan\Analyser\Scope;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;

/** @internal */
trait CollectsMailViews
{
    use CollectsViewNames;

    /**
     * Get the views a mail view argument names.
     *
     * Upstream reads only a string. The mailer also takes an array, which names the HTML and
     * plain-text views at 0 and 1, or under the html and text keys. Its raw key holds text.
     *
     * @return null|list<string>
     */
    private function getMailViews(?Expr $view, Scope $scope): ?array
    {
        if ($view === null) {
            return null;
        }

        $views = $this->getViewNames($view, $scope) ?? [];

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
