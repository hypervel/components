# Close registration before provider boot

## Design

Aspect rules and class-map replacements determine generated proxies. Registration after generation can silently miss a target, leave a method unintercepted, or bypass an existing proxy. Enforce the existing provider `register()` contract without adding work to method dispatch.

- Give `AspectCollector` and `ClassMapManager` a protected static boolean seal, with public `seal()` and `unseal()` methods. Document these worker-wide mutators as boot/test operations. Do not introduce shared traits, testing detection, snapshots, or additional state machinery.
- Check the seal inline in `AspectCollector::setAround()` and `ClassMapManager::add()` before mutation. `register()` already delegates to `setAround()`; no duplicate guard is needed. Throw `InvalidDefinitionException` for aspects and the existing `RuntimeException` for replacements, directing callers to provider `register()`.
- Reopen both registries at the start of `RegisterProviders::bootstrap()` so reloads and refreshes within one test can register providers again. Preserve existing rules/replacements when reopening. Testbench inherits this bootstrapper.
- Seal both registries after successful `GenerateProxies::bootstrap()`, including when there are no aspects or no matching targets. Keep standalone `generate()` reusable and non-sealing. Do not recover failed partial bootstraps.
- Add `AspectManager::clear()` to discard compiled chains. Call it whenever aspect rules are registered, forgotten, or reset; `AspectManager::flushState()` delegates to it. Rule changes own invalidation, so adding/removing aspects between focused test calls cannot retain stale dispatch. `unseal()` only changes the flag. Dispatch and chain execution gain no checks or counters.
- `flushState()` restores each registry to its initial open state. Existing framework cleanup still owns static cleanup. The PHPUnit extension already resets aspect rules before restoring its saved registrations.
- The PHPUnit extension explicitly reopens aspect registration before preparing temporary proxy metadata. ParaTest's parent process has already terminated its setup applications before loading extensions; retaining their closed registration window serves no caller. Keep the existing `finally` rule restoration and open end state. Do not add a seal getter or state-restoration machinery.
- Preserve focused `callWithAspects()` tests after application bootstrap through explicit `AspectCollector::unseal()`. No automatic PHPUnit or Testbench bypass. Normal provider `boot()` registration must fail in tests too.
- When Testbench `refreshApplication()` fails while creating a replacement app, restore the previous test application's container and facade globals, clear resolved facade instances, and rethrow the original exception. Its failed-app cleanup already flushes the replacement; leaving globals pointed there breaks subsequent facade calls and teardown. Limit recovery to failed creation with an existing test application; keep successful route setup outside the catch.
- Update `aop.md`, the advanced-provider section of `providers.md`, and class-map guidance in `packages.md`. Explain the closed boundary and explicit focused-testing opt-in concisely. Remove stale no-op wording and touched comments. This fixes Hypervel-only contracts and needs no Laravel porting-guide entry.

## Status

Implementation, verification, and peer review are complete.
