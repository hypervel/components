# Source Implementation Gaps

## Authentication

- Create hypervel/react-starter-kit
- Port Fortify package
- Port Passport package

## Artisan

- Create Hypervel Sail
- Port console command `Aliases`, `Help`, `Hidden`, and repeatable `Usage` attributes

## Authorization

- Add `Hypervel\Routing\Attributes\Controllers\Authorize`. Laravel has `Illuminate\Routing\Attributes\Controllers\Authorize`; Hypervel docs already reference the Hypervel equivalent, but the class does not exist. Correct fix: port Laravel's attribute, extending `Hypervel\Routing\Attributes\Controllers\Middleware` and using `Hypervel\Auth\Middleware\Authorize::using(...)`.
- Widen `Authorizable` ability types to accept `UnitEnum`. `Gate`, route `can()`, and `Authorize::using()` support enum abilities, but `Hypervel\Foundation\Auth\Access\Authorizable::can/canAny/cant/cannot` are typed as `iterable|string`, so `$user->can(Ability::UpdatePost)` currently TypeErrors. Correct fix: add `UnitEnum` to those method signatures and to `Hypervel\Contracts\Auth\Access\Authorizable::can`.
- Widen `Gate::allowIf()` / `Gate::denyIf()` `$code` type. Laravel allows arbitrary response codes; Hypervel's `Response` / `AuthorizationException` already support `int|string|null`, but `Gate::allowIf()` and `denyIf()` only accept `?string`. Correct fix: change those method signatures and facade docblocks to `int|string|null`.

## Blade

- Port Blade `@context` support. The copied Laravel Blade doc includes the `@context` / `@endcontext` directives, but Hypervel's `BladeCompiler` does not use Laravel's `CompilesContexts` concern and `src/view/src/Compilers/Concerns/CompilesContexts.php` does not exist. Hypervel already has the `context()` helper and `Hypervel\Support\Facades\Context`, so the correct fix is to port Laravel's compiler concern using Hypervel namespaces and add it to `BladeCompiler`.
- Complete `@use` support. Hypervel currently supports simple class imports and aliases, but the docs show grouped imports plus `function` and `const` imports. Laravel's `CompilesUseStatements` handles grouped imports and the `function` / `const` modifiers; Hypervel's implementation only splits the expression on commas and produces invalid output for those documented forms. Correct fix: port Laravel's newer parsing logic and the missing tests from Laravel's `BladeUseTest`.
- Port `@hasStack`. The docs include `@hasStack`, but Hypervel is missing Laravel's `compileHasStack()` method and `Hypervel\View\Concerns\ManagesStacks::isStackEmpty()`. Correct fix: add `compileHasStack()` to `CompilesConditionals`, add `isStackEmpty(string $section): bool` to `ManagesStacks` using the coroutine-backed push / prepend state, and port Laravel's Blade / stack tests.
- Port Laravel's `@fonts` Blade helper and related Vite fonts API if Hypervel wants full Blade / Vite parity. Laravel has `compileFonts()` in `CompilesHelpers` and a `Vite::fonts()` implementation; Hypervel has neither. This is not currently documented in `blade.md`, but it is a Laravel Blade helper that has not been ported.
- Add missing public Blade compiler API parity: `BladeCompiler::getPath()`, `BladeCompiler::setPath()`, and Laravel's nullable `compile($path = null)` behavior. Hypervel currently requires a string path and has no public getter / setter for the compiler path.
- Add missing `View::render(?callable $callback = null)` support. Hypervel has an internal `doRender(?callable $callback = null)` path, but the public `render()` method does not accept the optional callback that Laravel exposes.
- Bring `Hypervel\View\ComponentAttributeBag` closer to Laravel by implementing `Hypervel\Contracts\Support\Arrayable`, adding `toArray()`, supporting `all($keys = null)`, and using `Hypervel\Support\Traits\InteractsWithData`. Laravel exposes typed attribute access helpers through this trait; Hypervel's attribute bag currently lacks that API surface.
- Fix `CompilesComponents::compileProps()` helper variable cleanup. Laravel unsets `$__defined_vars`, `$__key`, and `$__value`; Hypervel currently only unsets `$__defined_vars`, so the generated component template can leak internal helper variables into scope.

## Http

- Port FailOnUnknownFields form request support

## Validation

- Port Rule::string() fluent string rule builder
