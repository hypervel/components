# Image Package

## Status

This is a preliminary handoff note, not a completed package audit or implementation plan. The Image package should be handled as a dedicated future work unit and is not part of the Filesystem audit.

## Upstream History

Laravel added first-party image processing in framework PR [#59276](https://github.com/laravel/framework/pull/59276) (`3dd4830499`). Follow-up framework PRs [#60713](https://github.com/laravel/framework/pull/60713) (`01abe68fa1`), [#60748](https://github.com/laravel/framework/pull/60748) (`950830b2a9`), and [#60824](https://github.com/laravel/framework/pull/60824) (`bafb8889e2`) extended and corrected the package. Laravel documentation PR #11264 added its documentation.

Use those pull requests to discover the complete historical change surface, but use Laravel's current default branch as the source for a future port.

## Known Surface

The feature is a coherent package rather than a Filesystem-only method. Its Laravel surface includes:

- the Image package, manager, image value/pipeline, output options, drivers, transformations, provider, metadata, and tests;
- Image driver and transformation contracts;
- image configuration and the Image facade;
- Filesystem and Storage integration through `FilesystemAdapter::image()`;
- HTTP Request integration through `Request::image()`;
- application provider and container-alias wiring;
- package dependencies, documentation, and facade metadata.

Do not port only `FilesystemAdapter::image()`. That would expose an orphan method whose return type and behavior depend on the missing package.

## Known Hypervel Lifecycle Concern

Laravel registers its mutable `ImageManager` as scoped. A direct port needs special review under Hypervel's container semantics: boot-time calls that resolve and configure a scoped manager can mutate the non-coroutine fallback instance, while request coroutines receive different scoped instances without those registrations. Rebuilding managers and image drivers for every request may also add unnecessary cost.

A future audit must settle the simplest correct ownership model for:

- manager driver caching;
- custom driver registration through `extend()`;
- custom transformation registration through `transformUsing()`;
- boot-time configuration visibility in request coroutines;
- driver and underlying Intervention Image manager concurrency safety;
- reset behavior for mutable worker-lifetime state and tests.

No manager lifetime or adaptation has been approved yet. Do not assume that Laravel's scoped provider or a worker-singleton replacement is correct without tracing these boundaries.

## Future Work

Treat Image as a dedicated Laravel-package port. Follow the incremental upstream workflow, inspect every file changed by the originating and follow-up pull requests, then port from the current Laravel default branch. Audit the complete package and all Filesystem, HTTP, Foundation, Support, configuration, contract, facade, test, and documentation integrations together before implementation.

The dedicated review should reject both an orphan Filesystem method and speculative lifecycle machinery. Any Hypervel adaptation should be limited to demonstrated coroutine-safety, worker-lifetime, performance, typing, or framework-integration requirements.
