<?php

declare(strict_types=1);

namespace Hypervel\Validation;

use Hypervel\Contracts\Validation\Validator;

class UnknownFields
{
    /**
     * Validate that input contains only known fields.
     *
     * @param null|array<string, array<int, array|object|string>> $unfilteredRules
     * @param list<string> $additionalFields
     * @param list<string> $allowedSubtrees
     */
    public static function validate(
        Validator $validator,
        array $input,
        ?array $unfilteredRules = null,
        array $additionalFields = [],
        array $allowedSubtrees = [],
    ): void {
        $rules = $unfilteredRules === null
            ? $validator->getRulesWithoutPlaceholders()
            : array_replace($unfilteredRules, $validator->getRulesWithoutPlaceholders());

        [$knownFields, $knownSubtrees, $wildcardFields, $wildcardSubtrees] = self::resolveKnownFields(
            $rules,
            $additionalFields,
            $allowedSubtrees,
        );

        self::validateInput(
            $validator,
            $input,
            $knownFields,
            $knownSubtrees,
            $wildcardFields,
            $wildcardSubtrees,
            inputSegments: $wildcardFields === [] && $wildcardSubtrees === [] ? null : [],
        );
    }

    /**
     * Validate input leaves against the known field paths.
     *
     * @param array<string, true> $knownFields
     * @param array<string, true> $knownSubtrees
     * @param list<list<null|string>> $wildcardFields
     * @param list<list<null|string>> $wildcardSubtrees
     * @param null|list<string> $inputSegments
     */
    private static function validateInput(
        Validator $validator,
        array $input,
        array $knownFields,
        array $knownSubtrees,
        array $wildcardFields,
        array $wildcardSubtrees,
        string $comparisonPrefix = '',
        string $displayPrefix = '',
        ?array $inputSegments = null,
    ): void {
        foreach ($input as $key => $value) {
            $key = (string) $key;
            $comparisonKey = $comparisonPrefix
                . str_replace(['.', '*'], ['\.', '\*'], $key);
            $displayKey = $displayPrefix . $key;
            $currentInputSegments = $inputSegments;

            if ($currentInputSegments !== null) {
                $currentInputSegments[] = $key;
            }

            if (is_array($value) && $value !== []) {
                self::validateInput(
                    $validator,
                    $value,
                    $knownFields,
                    $knownSubtrees,
                    $wildcardFields,
                    $wildcardSubtrees,
                    $comparisonKey . '.',
                    $displayKey . '.',
                    $currentInputSegments,
                );

                continue;
            }

            if (self::isKnownField(
                $comparisonKey,
                $currentInputSegments,
                $knownFields,
                $knownSubtrees,
                $wildcardFields,
                $wildcardSubtrees,
            )) {
                continue;
            }

            $message = $validator->getTranslator()->string('validation.prohibited', [
                'attribute' => str_replace('_', ' ', $displayKey),
            ]);

            $validator->errors()->add($displayKey, $message);
        }
    }

    /**
     * Resolve exact fields and opaque array subtrees from effective rules.
     *
     * @param array<string, array<int, array|object|string>> $rules
     * @param list<string> $additionalFields
     * @param list<string> $allowedSubtrees
     * @return array{
     *     array<string, true>,
     *     array<string, true>,
     *     list<list<null|string>>,
     *     list<list<null|string>>
     * }
     */
    private static function resolveKnownFields(
        array $rules,
        array $additionalFields,
        array $allowedSubtrees,
    ): array {
        [$knownFields, $wildcardFields] = self::resolveAuxiliaryPaths($additionalFields);
        [$opaqueSubtrees, $wildcardSubtrees] = self::resolveAuxiliaryPaths($allowedSubtrees);
        $fieldsWithDescendants = [];

        foreach (array_keys($rules) as $attribute) {
            $attribute = (string) $attribute;
            $knownFields[$attribute] = true;

            foreach (self::parentPaths($attribute) as $parent) {
                $fieldsWithDescendants[$parent] = true;
            }
        }

        foreach ($rules as $attribute => $attributeRules) {
            $attribute = (string) $attribute;

            foreach ($attributeRules as $rule) {
                [$rule, $parameters] = ValidationRuleParser::parse($rule);

                if ($rule === 'Confirmed') {
                    $knownFields[(string) ($parameters[0] ?? $attribute . '_confirmation')] = true;
                }

                if ($rule === 'Array' && ! isset($fieldsWithDescendants[$attribute])) {
                    $opaqueSubtrees[$attribute] = true;
                }
            }
        }

        return [$knownFields, $opaqueSubtrees, $wildcardFields, $wildcardSubtrees];
    }

    /**
     * Resolve exact and whole-segment wildcard auxiliary paths.
     *
     * @param list<string> $paths
     * @return array{array<string, true>, list<list<null|string>>}
     */
    private static function resolveAuxiliaryPaths(array $paths): array
    {
        $exactPaths = [];
        $wildcardPaths = [];

        foreach ($paths as $path) {
            $segments = self::parseAuxiliaryPath($path);

            if ($segments === null) {
                continue;
            }

            if (in_array(null, $segments, true)) {
                $wildcardPaths[] = $segments;
            } else {
                $exactPaths[$path] = true;
            }
        }

        return [$exactPaths, $wildcardPaths];
    }

    /**
     * Determine whether an input path is exact or inside an allowed subtree.
     *
     * @param null|list<string> $inputSegments
     * @param array<string, true> $knownFields
     * @param array<string, true> $allowedSubtrees
     * @param list<list<null|string>> $wildcardFields
     * @param list<list<null|string>> $wildcardSubtrees
     */
    private static function isKnownField(
        string $inputKey,
        ?array $inputSegments,
        array $knownFields,
        array $allowedSubtrees,
        array $wildcardFields,
        array $wildcardSubtrees,
    ): bool {
        if (isset($knownFields[$inputKey]) || isset($allowedSubtrees[$inputKey])) {
            return true;
        }

        foreach (self::parentPaths($inputKey) as $parent) {
            if (isset($allowedSubtrees[$parent])) {
                return true;
            }
        }

        if (($wildcardFields === [] && $wildcardSubtrees === []) || $inputSegments === null) {
            return false;
        }

        foreach ($wildcardFields as $pattern) {
            if (self::matchesPathPattern($pattern, $inputSegments)) {
                return true;
            }
        }

        foreach ($wildcardSubtrees as $pattern) {
            if (self::matchesPathPattern($pattern, $inputSegments, allowsDescendants: true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether input segments match an auxiliary path pattern.
     *
     * @param list<null|string> $pattern
     * @param list<string> $inputSegments
     */
    private static function matchesPathPattern(
        array $pattern,
        array $inputSegments,
        bool $allowsDescendants = false,
    ): bool {
        $patternLength = count($pattern);
        $inputLength = count($inputSegments);

        if ($inputLength < $patternLength
            || (! $allowsDescendants && $inputLength !== $patternLength)
        ) {
            return false;
        }

        foreach ($pattern as $index => $segment) {
            if ($segment !== null && $segment !== $inputSegments[$index]) {
                return false;
            }
        }

        return true;
    }

    /**
     * Parse an auxiliary path or reject a partial wildcard segment.
     *
     * @return null|list<null|string>
     */
    private static function parseAuxiliaryPath(string $path): ?array
    {
        $segments = [];
        $segment = '';
        $hasUnescapedAsterisk = false;
        $length = strlen($path);

        for ($position = 0; $position < $length; ++$position) {
            $character = $path[$position];

            if ($character === '\\'
                && $position + 1 < $length
                && in_array($path[$position + 1], ['.', '*'], true)
            ) {
                $segment .= $path[++$position];

                continue;
            }

            if ($character === '.') {
                if ($hasUnescapedAsterisk && $segment !== '*') {
                    return null;
                }

                $segments[] = $hasUnescapedAsterisk ? null : $segment;
                $segment = '';
                $hasUnescapedAsterisk = false;

                continue;
            }

            $hasUnescapedAsterisk = $hasUnescapedAsterisk || $character === '*';
            $segment .= $character;
        }

        if ($hasUnescapedAsterisk && $segment !== '*') {
            return null;
        }

        $segments[] = $hasUnescapedAsterisk ? null : $segment;

        return $segments;
    }

    /**
     * Get parent paths using Validator's escaped-dot notation.
     *
     * @return list<string>
     */
    private static function parentPaths(string $path): array
    {
        $parents = [];

        // Validator does not escape backslashes themselves, so a raw key ending
        // in a backslash before a child is interpreted as escaped and fails closed.
        for ($position = strlen($path) - 1; $position >= 0; --$position) {
            if ($path[$position] !== '.' || ($position > 0 && $path[$position - 1] === '\\')) {
                continue;
            }

            $parents[] = substr($path, 0, $position);
        }

        return $parents;
    }
}
