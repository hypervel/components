# Differences vs Laravel

Intentional behavioral differences between Hypervel and Laravel.

## Event Dispatch

- **`hasListeners()` guards skip event construction when no listeners exist.** Framework code checks `hasListeners()` before constructing event objects. If nothing is listening, the event is never created or dispatched. This is a Swoole performance optimization — Laravel always constructs and dispatches events regardless of listeners.

- **Catch-all wildcard listeners (`*`) are passive observers.** A `listen('*', ...)` registration is not counted by `hasListeners()`. Wildcard listeners still receive events during dispatch, but they are not considered "interested" listeners that justify constructing an event. Targeted wildcards (e.g. `App\Events\*`) are still counted. This prevents observability tools like Telescope's EventWatcher from defeating the `hasListeners()` guards.
