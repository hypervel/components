<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules\Validation;

use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ParametersAcceptor;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\RuleErrorBuilder;

use function array_any;
use function array_key_last;
use function array_map;
use function array_push;
use function array_values;
use function count;
use function explode;
use function implode;
use function in_array;
use function sprintf;
use function str_contains;
use function strpos;
use function strtolower;
use function trim;
use function var_export;

/**
 * Finds the validation rule strings the framework splits at "|", where they are written.
 *
 * The validator splits a string given as one attribute's rules, but never the strings of a
 * rules array, so `['regex:/a|b/']` keeps its pattern. Data splits strings at any depth,
 * except one whose rule is a regex.
 *
 * @phpstan-type Shape self::MAP|self::SET|self::ANY_OF|self::PROMPT|self::DATA_MAP|self::DATA_SET
 */
#[AutowiredService]
class ValidationRuleHelper
{
    /**
     * Attributes mapped to their rules, as Validator::make() takes them.
     */
    public const string MAP = 'map';

    /**
     * One attribute's rules.
     */
    public const string SET = 'set';

    /**
     * Rule::anyOf()'s alternatives: rules mapped by attribute when keyed, otherwise one set of rules.
     */
    public const string ANY_OF = 'anyOf';

    /**
     * A prompt's validate argument: one set of rules, or a keyed array whose first value is one.
     */
    public const string PROMPT = 'prompt';

    /**
     * A Data class's attributes mapped to their rules.
     */
    public const string DATA_MAP = 'dataMap';

    /**
     * Data rules, at any depth.
     */
    public const string DATA_SET = 'dataSet';

    /**
     * Get the argument values the call passes for the given parameter, none when an argument is unpacked.
     *
     * @param array<Arg> $arguments
     * @param list<ParametersAcceptor> $variants
     *
     * @return list<Expr>
     */
    public function getArgumentValues(array $arguments, array $variants, string $parameter, Scope $scope): array
    {
        if (array_any($arguments, static fn (Arg $argument): bool => $argument->unpack)) {
            return [];
        }

        $parameters = ParametersAcceptorSelector::selectFromArgs($scope, $arguments, $variants)->getParameters();
        $last = $parameters === [] ? null : $parameters[array_key_last($parameters)];
        $values = [];

        // Positional arguments come first, and those past the last parameter belong to it when it is variadic.
        foreach (array_values($arguments) as $position => $argument) {
            $positional = $parameters[$position] ?? ($last?->isVariadic() ? $last : null);

            if (($argument->name?->toString() ?? $positional?->getName()) === $parameter) {
                $values[] = $argument->value;
            }
        }

        return $values;
    }

    /**
     * Report the rule strings in a validation value of the given shape that are split at "|".
     *
     * @param Shape $shape
     *
     * @return list<IdentifierRuleError>
     */
    public function check(Expr $value, string $shape, Scope $scope): array
    {
        return match ($shape) {
            self::MAP => $this->checkItems($value, self::SET, $scope),
            self::DATA_MAP => $this->checkItems($value, self::DATA_SET, $scope),
            self::SET => $this->checkString($value, false, $scope),
            self::DATA_SET => $value instanceof Array_
                ? $this->checkItems($value, self::DATA_SET, $scope)
                : $this->checkString($value, true, $scope),
            self::ANY_OF => $this->checkAlternatives($value, $scope),
            self::PROMPT => $this->checkPrompt($value, $scope),
        };
    }

    /**
     * Report the split rule strings among the values of an array literal.
     *
     * @param Shape $shape
     *
     * @return list<IdentifierRuleError>
     */
    private function checkItems(Expr $value, string $shape, Scope $scope): array
    {
        if (! $value instanceof Array_) {
            return [];
        }

        $errors = [];

        foreach ($value->items as $item) {
            if (! $item->unpack) {
                array_push($errors, ...$this->check($item->value, $shape, $scope));
            }
        }

        return $errors;
    }

    /**
     * Report the split rule strings among Rule::anyOf()'s alternatives, read as AnyOf::passes() reads them.
     *
     * @return list<IdentifierRuleError>
     */
    private function checkAlternatives(Expr $value, Scope $scope): array
    {
        if (! $value instanceof Array_) {
            return [];
        }

        $errors = [];

        foreach ($value->items as $item) {
            if ($item->unpack) {
                continue;
            }

            $alternative = $item->value;

            array_push($errors, ...match (true) {
                ! $alternative instanceof Array_ => $this->checkString($alternative, false, $scope),
                $scope->getType($alternative)->isList()->no() => $this->check($alternative, self::MAP, $scope),
                default => [],
            });
        }

        return $errors;
    }

    /**
     * Report a split rule string given as a prompt's rules, read as validatePrompt() reads them.
     *
     * @return list<IdentifierRuleError>
     */
    private function checkPrompt(Expr $value, Scope $scope): array
    {
        if (! $value instanceof Array_) {
            return $this->checkString($value, false, $scope);
        }

        // A keyed array names the attribute with its first key and gives the rules as its first value.
        $first = $value->items[0] ?? null;

        return $first !== null && ! $first->unpack && $scope->getType($value)->isList()->no()
            ? $this->checkString($first->value, false, $scope)
            : [];
    }

    /**
     * Report a rule string that is split at "|".
     *
     * @return list<IdentifierRuleError>
     */
    private function checkString(Expr $value, bool $data, Scope $scope): array
    {
        $type = $scope->getType($value);

        $strings = $type->getConstantStrings();

        if ($type->isString()->yes() && $strings !== []) {
            foreach ($strings as $string) {
                $rules = $string->getValue();

                if (str_contains($rules, '|') && ! ($data && $this->isRegex($rules))) {
                    // One of several values has no single replacement to name.
                    return [$this->buildError($value, count($strings) === 1 ? $rules : null)];
                }
            }

            return [];
        }

        if (! $value instanceof Concat && ! $value instanceof InterpolatedString) {
            return [];
        }

        $pieces = $this->getPieces($value, $scope);

        if (! array_any($pieces, static fn (?string $piece): bool => $piece !== null && str_contains($piece, '|'))) {
            return [];
        }

        // Data keeps a string starting with a regex rule whole, so its rule name must be known.
        if ($data && ! $this->hasNonRegexName($pieces[0] ?? null)) {
            return [];
        }

        return [$this->buildError($value, null)];
    }

    /**
     * Get the known text of each part of a built string, or null for a part that is not constant.
     *
     * @return list<null|string>
     */
    private function getPieces(Expr $value, Scope $scope): array
    {
        $strings = $scope->getType($value)->getConstantStrings();

        if (count($strings) === 1) {
            return [$strings[0]->getValue()];
        }

        return match (true) {
            $value instanceof Concat => [...$this->getPieces($value->left, $scope), ...$this->getPieces($value->right, $scope)],
            $value instanceof InterpolatedString => array_map(
                fn (Expr|InterpolatedStringPart $part): ?string => $part instanceof InterpolatedStringPart
                    ? $part->value
                    : $this->getPieces($part, $scope)[0],
                $value->parts,
            ),
            default => [null],
        };
    }

    /**
     * Determine whether the known start of a rule string shows a rule name that is not a regex.
     */
    private function hasNonRegexName(?string $start): bool
    {
        if ($start === null) {
            return false;
        }

        $colon = strpos($start, ':');
        $pipe = strpos($start, '|');

        // A name running into "|" cannot be a regex rule's name.
        if ($pipe !== false && ($colon === false || $pipe < $colon)) {
            return true;
        }

        return $colon !== false && ! $this->isRegex($start);
    }

    /**
     * Determine whether a rule string's name is a regex rule, as ValidationRuleParser::ruleIsRegex() matches it.
     */
    private function isRegex(string $rule): bool
    {
        return in_array(strtolower(explode(':', $rule, 2)[0]), ['regex', 'not_regex', 'notregex'], true);
    }

    /**
     * Build the error for split rules, with the array to use when the rules are known and splitting them is unambiguous.
     */
    private function buildError(Expr $value, ?string $rules): IdentifierRuleError
    {
        $segments = $rules === null ? [] : explode('|', $rules);
        // A regex is split at its own "|", so where it ends is unknown.
        $regex = array_any($segments, $this->isRegex(...));
        $unambiguous = $rules !== null
            && ! $regex
            && ! array_any($segments, static fn (string $segment): bool => $segment === '' || $segment !== trim($segment));

        $error = RuleErrorBuilder::message(match (true) {
            $rules === null => 'Validation rules are pipe-separated; list them in an array.',
            $unambiguous => sprintf(
                'Validation rules %s are pipe-separated; list them in an array: [%s].',
                var_export($rules, true),
                implode(', ', array_map(static fn (string $segment): string => var_export($segment, true), $segments)),
            ),
            default => sprintf('Validation rules %s are pipe-separated; list them in an array.', var_export($rules, true)),
        })
            ->identifier('hypervel.pipeSeparatedValidationRules')
            ->line($value->getStartLine());

        if ($regex) {
            $error->addTip('A regex containing "|" must be its own array element.');
        }

        if ($unambiguous && $value instanceof String_) {
            $error->fixNode($value, static function (String_ $string): Array_ {
                // The fixer passes its own copy of the string; the new elements keep its quoting.
                $attributes = in_array($string->getAttribute('kind'), [String_::KIND_SINGLE_QUOTED, String_::KIND_DOUBLE_QUOTED], true)
                    ? ['kind' => $string->getAttribute('kind')]
                    : [];

                return new Array_(
                    array_map(static fn (string $segment): ArrayItem => new ArrayItem(new String_($segment, $attributes)), explode('|', $string->value)),
                    ['kind' => Array_::KIND_SHORT],
                );
            });
        }

        return $error->build();
    }
}
