<?php

declare(strict_types=1);

namespace Hypervel\Di\Aop;

class Aspect
{
    /**
     * Parse the aspects that target the given class.
     */
    public static function parse(string $class): RewriteCollection
    {
        $rewriteCollection = new RewriteCollection($class);
        $classesCollection = AspectCollector::get('classes', []);

        if ($classesCollection) {
            self::parseClasses($classesCollection, $class, $rewriteCollection);
        }

        return $rewriteCollection;
    }

    /**
     * Determine if a target matches a class rule.
     *
     * @return array{bool, ?string} [isMatch, matchedMethod]
     */
    public static function isMatchClassRule(string $target, string $rule): array
    {
        /*
         * e.g. Foo\Bar
         * e.g. Foo\B*
         * e.g. F*o\Bar
         * e.g. Foo\Bar::method
         * e.g. Foo\Bar::met*
         */
        $ruleMethod = null;
        $ruleClass = $rule;
        $method = null;
        $class = $target;

        if (str_contains($rule, '::')) {
            [$ruleClass, $ruleMethod] = explode('::', $rule);
        }
        if (str_contains($target, '::')) {
            [$class, $method] = explode('::', $target);
        }

        if ($method === null) {
            if (! str_contains($ruleClass, '*')) {
                /*
                 * Match [rule] Foo\Bar::ruleMethod [target] Foo\Bar [return] true,ruleMethod
                 * Match [rule] Foo\Bar [target] Foo\Bar [return] true,null
                 * Match [rule] FooBar::rule*Method [target] Foo\Bar [return] true,rule*Method
                 */
                if ($ruleClass === $class) {
                    return [true, $ruleMethod];
                }

                return [false, null];
            }

            /*
             * Match [rule] Foo*Bar::ruleMethod [target] Foo\Bar [return] true,ruleMethod
             * Match [rule] Foo*Bar [target] Foo\Bar [return] true,null.
             */
            $preg = str_replace(['*', '\\'], ['.*', '\\\\'], $ruleClass);
            $pattern = "#^{$preg}$#";

            if (preg_match($pattern, $class)) {
                return [true, $ruleMethod];
            }

            return [false, null];
        }

        if (! str_contains($rule, '*')) {
            /*
             * Match [rule] Foo\Bar::ruleMethod [target] Foo\Bar::ruleMethod [return] true,ruleMethod
             * Match [rule] Foo\Bar [target] Foo\Bar::ruleMethod [return] false,null
             */
            if ($ruleClass === $class && ($ruleMethod === null || $ruleMethod === $method)) {
                return [true, $method];
            }

            return [false, null];
        }

        /*
         * Match [rule] Foo*Bar::ruleMethod [target] Foo\Bar::ruleMethod [return] true,ruleMethod
         * Match [rule] FooBar::rule*Method [target] Foo\Bar::ruleMethod [return] true,rule*Method
         */
        if ($ruleMethod) {
            $preg = str_replace(['*', '\\'], ['.*', '\\\\'], $rule);
            $pattern = "#^{$preg}$#";
            if (preg_match($pattern, $target)) {
                return [true, $method];
            }
        } else {
            /*
             * Match [rule] Foo*Bar [target] Foo\Bar::ruleMethod [return] true,null.
             */
            $preg = str_replace(['*', '\\'], ['.*', '\\\\'], $rule);
            $pattern = "#^{$preg}$#";
            if (preg_match($pattern, $class)) {
                return [true, $method];
            }
        }

        return [false, null];
    }

    /**
     * Determine if a class method matches a rule.
     */
    public static function isMatch(string $class, string $method, string $rule): bool
    {
        [$isMatch] = self::isMatchClassRule($class . '::' . $method, $rule);

        return $isMatch;
    }

    /**
     * Parse class-targeted aspect rules against the given class.
     */
    private static function parseClasses(array $collection, string $class, RewriteCollection $rewriteCollection): void
    {
        $aspects = array_keys($collection);
        foreach ($aspects as $aspect) {
            $rules = AspectCollector::getRule($aspect);
            foreach ($rules['classes'] ?? [] as $rule) {
                [$isMatch, $method] = static::isMatchClassRule($class, $rule);
                if ($isMatch) {
                    if ($method === null) {
                        $rewriteCollection->setLevel(RewriteCollection::CLASS_LEVEL);
                        return;
                    }
                    $rewriteCollection->add($method);
                }
            }
        }
    }
}
