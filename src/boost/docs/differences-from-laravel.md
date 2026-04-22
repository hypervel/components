# Differences from Laravel

If you know Laravel, this page lists the places where Hypervel intentionally does things differently, and what to use in their place. Each entry is a short summary linking to the detailed explanation in the relevant feature doc.

## HTTP Client

- **`Http::pool` / `Http::batch`** — use `parallel()` from `Hypervel\Coroutine` instead. Hypervel's coroutine architecture makes dedicated pool and batch methods unnecessary. → [Concurrent Requests](/docs/{{version}}/http-client#concurrent-requests)
