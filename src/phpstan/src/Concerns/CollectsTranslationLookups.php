<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Concerns;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\TypeCombinator;

use function array_map;

/**
 * Upstream collects only the key. The missing-translation rule resolves each lookup through the
 * booted translator, so the call's locale, fallback and operation are collected with it.
 *
 * @internal
 *
 * @phpstan-type TranslationLookup array{key: string, line: int, locales: list<null|string>, fallback: bool, choice: bool}
 */
trait CollectsTranslationLookups
{
    /**
     * Get the lookup made by a call with a literal translation key.
     *
     * @param bool $choice whether the call is a choice() lookup, whose locale follows the count
     * @param bool $acceptsFallback whether the call has get()'s fallback parameter
     * @return null|TranslationLookup
     */
    protected function translationLookup(CallLike $call, bool $choice, bool $acceptsFallback, ?Scope $scope): ?array
    {
        foreach ($call->getArgs() as $argument) {
            // Arguments after an unpacked one have unknown positions.
            if ($argument->unpack) {
                return null;
            }
        }

        $key = $call->getArg('key', 0)?->value;

        if (! $key instanceof String_) {
            return null;
        }

        $locales = $this->translationLocales($call->getArg('locale', $choice ? 3 : 2)?->value, $scope);

        if ($locales === null) {
            return null;
        }

        $fallback = ! $acceptsFallback || $this->translationFallback($call->getArg('fallback', 3)?->value, $scope);

        return [
            'key' => $key->value,
            'line' => $call->getStartLine(),
            'locales' => $locales,
            'fallback' => $fallback,
            'choice' => $choice,
        ];
    }

    /**
     * Get the locales a lookup can use, where null is the translator's current locale.
     *
     * @return null|list<null|string>
     */
    protected function translationLocales(?Expr $locale, ?Scope $scope): ?array
    {
        if ($locale === null) {
            return [null];
        }

        if ($scope === null) {
            return match (true) {
                $locale instanceof String_ => [$locale->value],
                $locale instanceof ConstFetch && $locale->name->toLowerString() === 'null' => [null],
                default => null,
            };
        }

        $type = $scope->getType($locale);
        $strings = TypeCombinator::removeNull($type)->getConstantStrings();

        if ($strings === []) {
            return $type->isNull()->yes() ? [null] : null;
        }

        $locales = array_map(static fn (ConstantStringType $string): string => $string->getValue(), $strings);

        if ($type->isNull()->maybe()) {
            $locales[] = null;
        }

        return $locales;
    }

    /**
     * Determine whether a lookup can use the fallback locale.
     *
     * An unknown value counts as true: a translation missing with the fallback
     * locale is also missing without it.
     */
    protected function translationFallback(?Expr $fallback, ?Scope $scope): bool
    {
        if ($fallback === null) {
            return true;
        }

        if ($scope === null) {
            return ! ($fallback instanceof ConstFetch && $fallback->name->toLowerString() === 'false');
        }

        return ! $scope->getType($fallback)->isFalse()->yes();
    }
}
