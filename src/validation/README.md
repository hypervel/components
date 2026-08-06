Validation for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/validation)

Documentation: https://hypervel.org/docs/validation

## Differences From Laravel

- Scalar `in` and `not_in` rules compare the submitted value with the rule's literal values as strings. Numeric strings are not loosely coerced.
- Date comparison rules allow a referenced field to be missing or `null` unless it is also required. Unparseable date strings and invalid referenced values fail validation instead of being compared with `null`.

Ported from: https://github.com/laravel/framework
