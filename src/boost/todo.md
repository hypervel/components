# Source Implementation Gaps Identified From Docs

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

## Http

- Port FailOnUnknownFields form request support

## Validation

- Port Rule::string() fluent string rule builder
