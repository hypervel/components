Validation for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/validation)

Documentation: https://hypervel.org/docs/validation

## Differences From Laravel

- String rule parameters use standard CSV quoting with literal backslashes. See [rule parameters](https://hypervel.org/docs/validation#rule-parameters).
- The `encoding` rule rejects numbers, booleans, and non-nullable `null`; it does not coerce scalar values to strings.
- Date comparison rules allow a referenced field to be missing or `null` unless it is also required. Unparseable date strings and invalid referenced values fail validation instead of being compared with `null`.
- Rule keys may escape a literal asterisk as `\*`, matching the existing `\.` literal-dot syntax.
- Laravel's deprecated `InvokableRule` contract is not provided. Implement `ValidationRule` and its `validate()` method instead.
- Database-rule extensions use typed conditions and stateless presence queries. Override `queryConditions()` and `queryParameters()` instead of `__toString()`; declarations are captured at each validation run's start, and `getRules()` retains the builders as objects. See [customizing database rules](https://hypervel.org/docs/validation#customizing-database-rules).
- Membership builders use `toArray()` as their overridable declaration, with final `__toString()` methods; `getRules()` retains the objects. See [extending fluent rules](https://hypervel.org/docs/validation#extending-fluent-rules).
- Value-membership rules preserve float precision and consistently match booleans with `0`/`1` and their string forms, while keeping null and empty strings distinct. See [membership comparisons](https://hypervel.org/docs/validation#membership-comparisons).

Ported from: https://github.com/laravel/framework
