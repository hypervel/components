# Framework Correctness Improvements

## Scope and status

Independently useful database, queue, validation, and decimal-casting improvements. Keep this plan independent of private applications and packages; describe framework requirements without their names, paths, or domain details. Preserve Laravel signatures and protected extension points, except for explicitly approved behavior changes.

The changes below are implemented and verified; merging remains. Record further framework changes here when they warrant planning, without duplicating consumer-specific implementation plans.

## Design

- **Database capability:** add `Connection::supportsSkipLocked(): bool`, false by default, rather than widening the driver-neutral `ConnectionInterface`. PDO implementations reuse the current engine/version decision and its connection-local memoization/reset behavior. Preserve configured version overrides and MariaDB detection through either driver: MariaDB 10.6+, MySQL 8.0.1+, Vitess/PlanetScale 19+, PostgreSQL 9.5+; SQLite remains false. Migrate Database Queue's internal capability use while preserving `getLockForPopping(): bool|string` and its invocation. Do not retain parallel engine/version logic; retire superseded `@internal` popping helpers after their callers migrate.
- **Query-builder API:** add fluent `lockForUpdate()->skipLocked()` without changing `lockForUpdate()` or `sharedLock()` signatures. The no-argument modifier requires an existing update/shared lock; reject custom-string combinations in either setter order. Carry intent structurally; grammars own engine SQL, including MySQL's `for share` versus MariaDB's `lock in share mode`. Unsupported versions retain their normal lock behavior; callers needing a nonblocking claim check the connection capability and choose compare-and-swap updates. No lock-mode class hierarchy or domain-specific framework claim service.
- **Queue middleware:** add `RateLimited::releasePadding(int $seconds): static`, nonnegative, with a default of three seconds preserving current behavior. `releaseAfter()` retains fixed-delay precedence. Include the property in the serialization whitelist. Callers can opt into `releasePadding(0)` without additional I/O or lookup for existing jobs.
- **Validation defect:** guard both numeric field operands of `gt/gte/lt/lte` with their respective attribute names. Guard `multiple_of` input through `validateMultipleOf()` in compiled and delegated execution, preserving the calculation hook's signature. Exponent rejection and numeric parse errors fail validation rather than escaping as math exceptions. Comparison-message formatting preserves the `getSize()` hook and displays the original trimmed value if its exponent is rejected, without expanding it or throwing again. Preserve the attribute-aware override callback; developer-supplied rule literals remain trusted. Components already require Brick Math 1.0; no further version migration is needed.
- **Decimal casts:** prevent scientific-notation expansion beyond ±1000 in request and Eloquent decimal casts, throwing their existing math exception. Share exponent extraction through internal `Number::exponent()` and the default `Number::MAX_EXPONENT` with validation, without limiting ordinary decimal-string length or changing rational parsing. Include `int` in Eloquent's decimal-cast input type so integer values retain their precision instead of passing through float conversion. Test both casts, exponent boundaries, unchanged explicit decimals/fractions and large integer precision with bounded inputs.

## Verification

- Reuse the existing database version/override/PDO-reset and queue-hook cases; add the additive builder grammar/unsupported-path checks and real multi-connection lock contention coverage.
- Test limiter default/fixed/zero-padding/long-delay behavior and serialization.
- Test exponent rejection on both comparison operands and `multiple_of`, callback overrides, and compiled/interpreted parity using safe inputs such as `1e1001`, not memory-exhaustion experiments.
- Run each affected test file immediately and finish owning-package checks. Run formatting, PHPStan, and targeted tests in that order; retain applicable upstream tests.
- No cache change is required: stack TTL caps, nullable unique-lock stores, and documented flexible-cache microcache behavior are already handled by current components.
