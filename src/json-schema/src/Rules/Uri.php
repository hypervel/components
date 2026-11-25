<?php

declare(strict_types=1);

namespace Hypervel\JsonSchema\Rules;

use Closure;
use Hypervel\Contracts\Validation\ValidationRule;
use Hypervel\Translation\PotentiallyTranslatedString;

/**
 * Validate the JSON Schema "uri" format: an absolute URI as defined by RFC 3986, in which
 * non-ASCII characters must be percent-encoded.
 */
class Uri implements ValidationRule
{
    /**
     * The URI components, matched in character runs so long values cannot exhaust the regex engine.
     *
     * Percent signs are checked separately, and a bracketed host is validated natively.
     */
    protected const string PATTERN = <<<'REGEX'
        {^[A-Za-z][A-Za-z0-9+.\-]*+:
        (?:
            //(?:[A-Za-z0-9\-._~!$&'()*+,;=:%]*+@)?
            (?:\[(?<literal>[^\]]*+)\]|[A-Za-z0-9\-._~!$&'()*+,;=%]*+)
            (?::[0-9]*+)?
            (?=[/?\#]|\z)
            [A-Za-z0-9\-._~!$&'()*+,;=:@/%]*+
            | (?!//)[A-Za-z0-9\-._~!$&'()*+,;=:@/%]*+
        )
        (?:\?[A-Za-z0-9\-._~!$&'()*+,;=:@/?%]*+)?
        (?:\#[A-Za-z0-9\-._~!$&'()*+,;=:@/?%]*+)?
        \z}x
        REGEX;

    /**
     * Run the validation rule.
     *
     * @param Closure(string, ?string=): PotentiallyTranslatedString $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! $this->isUri($value)) {
            $fail('validation.regex')->translate();
        }
    }

    /**
     * Determine if the value is an absolute URI.
     */
    protected function isUri(string $value): bool
    {
        // @TODO: Replace these checks with Uri\Rfc3986\Uri::parse(), still requiring a scheme, once the minimum PHP version is 8.5.
        if (preg_match(static::PATTERN, $value, $matches, PREG_UNMATCHED_AS_NULL) !== 1
            || preg_match('/%(?![0-9A-Fa-f]{2})/', $value) !== 0) {
            return false;
        }

        $literal = $matches['literal'] ?? null;

        return $literal === null
            || filter_var($literal, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            || preg_match('{^v[0-9a-f]++\.[A-Za-z0-9\-._~!$&\'()*+,;=:]++\z}i', $literal) === 1;
    }
}
