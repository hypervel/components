# Route matching across cached routes, runtime routes and listeners

## Matching contract

A cached route collection and routes registered after loading the cache serve one HTTP endpoint. If an actual route accepts the request, dispatch it. Otherwise a 405 advertises every method accepted for that host, scheme, listener and path, once each. An automatic OPTIONS response uses the same set. If nothing matches on any method, return the framework's normal 404.

Before this fix, with cached GET and runtime POST on `/users`, DELETE advertised only POST. Reversing registration advertised only GET and HEAD. Both routes dispatch successfully. Laravel's compiled collection delegates the entire miss to the runtime collection too. Hypervel can correct the response within the existing matcher and exception APIs.

## Listener matching and link generation

`Route::port()` selects the listener that received the request. RequestBridge already copies Swoole's server port into `SERVER_PORT`. Read that value for route selection in both matchers. Keep `Request::getPort()` as the effective public URL port, including trusted proxy handling. A request to the public HTTPS origin can arrive on listener 8000. Changing its Host header must not select a different listener.

Keep port constraints on routes and groups. RouteCollection keys registrations by method, domain and URI, so overlapping registrations with that same key still require the same port. Explain the storage restriction in both registration errors. Document listener matching beside domain routing, with a group example and how another matching route can handle a request on a different listener.

Preserve public URLs for links generated behind a proxy. Previously a request for `https://example.test` on listener 8000 generated `https://example.test:8000` for a route on that listener. An absolute signature computed from that URL fails verification at the public origin. Use the normal public origin for all generated links, including named domains, signed URLs and console requests derived from `app.url`. Remove route-port injection and restore Laravel's request-based default-port handling. A server-side listener cannot identify the public port or host a proxy publishes. For cross-listener links on separate public origins, map the route with the existing route-aware `URL::formatHostUsing()` hook before signing. This also handles directly exposed ports. Cover explicit origin paths and relative URLs through the existing formatting paths.

## Compiled route precedence

A post-match port check is too late. For example, a cached GET restricted to another listener can hide a later GET wildcard that would serve the request. Recovering the next eligible route after the match would require rescanning cached routes. Select the listener’s table before matching so Symfony resolves precedence, captures and allowed methods in one pass.

- At construction, identify the ports actually declared in cached route metadata. For port-scoped collections, prepare the table for each declared port and a shared table for all other listeners. Share unchanged arrays, compiled regexes and condition callbacks. Only filter groups containing excluded routes. Never store a table for an arbitrary incoming port.
- Filter candidates by route name in static path groups and dynamic groups. Keep dynamic MARK keys, even if a group is empty, and preserve Symfony's end-of-regexp sentinel rows. The native matcher still decides order, captures, fallbacks, conditions, schemes, 404 and allowed verbs. Keep the serialized cache format usable without regeneration.
- Preserve direct matching of eligible static paths and the existing optimization for a known static miss. Select tables once per request. The no-port case needs no listener read. Bind the selected cached route from the compiled result, including custom Route binding behavior.
- Remove the obsolete post-match wrong-port retry. For a cached fallback, try only an actual runtime match for the requested method. Only a concrete runtime route can displace the cached fallback.

## Allowed methods and misses

On Symfony's method rejection, use its already validated method list. A runtime route may still accept the requested method. Match that method once using the existing route validators and bind only the winner. If there is no winner, combine the compiled verbs and the runtime collection's alternate verbs and delegate to `getRouteForMethods()`. Its existing implementation owns automatic OPTIONS and the 405 exception. The existing response builder takes the merged list.

```php
$methods = array_values(array_unique([...$compiledMethods, ...$runtimeMethods]));

return $this->getRouteForMethods($request, $methods);
```

Preserve GET/HEAD, explicit OPTIONS and explicitly registered custom verbs. The plain collection must discover custom verbs on wrong-method requests as well. Check the registered method groups once and preserve the established ordering for standard verbs. Keep the existing protected matching APIs usable by subclasses.

When Symfony reports no matching resource, delegate directly to the runtime collection if one exists. Its 404 or 405 is final. Scanning the full cached collection again needlessly resolves route objects on ordinary 404 traffic. Likewise, scanning combined cached and runtime buckets on a mixed 405 both materializes unrelated routes and can hide a cached route with the same method and URI but a different parameter constraint. No worker-level request-result cache or new configuration is needed.

## Verification and commits

Use the existing routing tests to assert observable routes, bound parameters, headers, signatures and exception statuses.

- Exercise cached GET plus runtime POST and the reverse on DELETE and automatic OPTIONS. Check the complete Allow set, implicit HEAD, duplicate methods, explicit handlers, different parameter constraints and a custom verb in plain and mixed matching.
- Cover a port-restricted literal and parameterized route before a broader cached route. Check the selected name and captures on both listeners, wrong-port alternate methods, an explicit OPTIONS restricted to another listener, and a true 404. Exercise a Host/public-port mismatch with cached and uncached routes.
- Check proxied absolute and signed links at the public origin, named domains, and explicit cross-listener origin mappings. Retain explicit public-origin path coverage. Exercise the existing route-cache round trip and the routing fallback tests. Trace performance-sensitive paths without asserting private counters or timing thresholds.
- Run each changed test file immediately. At the final checkpoint run CS Fixer, PHPStan and the affected tests sequentially, then trace callers and review the full diff before peer code review.
- Record each independently verified defect in its own whole-file commit with a body. Keep listener-port behavior and its coverage in a dedicated commit. Remove the completed 405 item from `docs/todo.md` with the 405 fix.
