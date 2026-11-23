# Threat model

## What this project does and where untrusted input enters

Hypervel Framework (`src/<package>/src`, under the `Hypervel` namespace) is a PHP web application framework.
It is a library: applications built on it handle requests from the internet, and the framework parses,
validates, stores, and renders that data.

Applications run in long-lived Swoole workers, with concurrent requests and jobs in separate coroutines.
Unbound concrete services are auto-singletons. Singletons and static state persist between requests;
request-owned state belongs in coroutine-local storage.

Treat the following as attacker-controlled:

- Everything in an HTTP request: the method, path, query string, headers (including `Host` and `X-Forwarded-*`),
  cookies, body, JSON, and uploaded files (name, MIME type, and contents).
- Route parameters, signed URL parameters, pagination parameters, and redirect targets that come from a request.
- Encrypted cookies, session cookies, CSRF tokens, password reset tokens, and "remember me" tokens that a client
  sends back.
- Data that an application validates with `Hypervel\Validation` before use, and data it renders with Blade's
  escaped `{{ }}` syntax.
- Email addresses, file names, and other strings that an application passes to the framework after validation.

Treat the following as trusted:

- `APP_KEY`, `.env`, the `config/` files, the service container bindings, and the application's own code.
- The contents of the database, cache, queue, session store, and Redis server, unless an attacker can write to
  them through the framework itself.
- Artisan commands and their arguments. Only operators run Artisan.

## Components that matter most / least

Highest priority:

1. Encryption, cookies, and sessions: `Hypervel\Encryption`, `Hypervel\Cookie` (`EncryptCookies`),
   `Hypervel\Session`. MAC bypass, padding or key-handling flaws, session fixation, and any path that decrypts
   or unserializes client data without the application asking for it.
2. Authentication and authorization: `Hypervel\Auth` (guards, password reset, remember-me, `Gate` and
   policies), password confirmation, and email verification.
3. Routing and URLs: `Hypervel\Routing` (signed URLs, route model binding, middleware ordering, redirects),
   CSRF and maintenance mode bypass in `Hypervel\Foundation\Http\Middleware`, and `TrustProxies`, `TrustHosts`,
   and `ValidatePostSize` in `Hypervel\Http\Middleware`.
4. Validation bypass in `Hypervel\Validation` rules that applications rely on for security, such as `email`,
   `url`, `file`, `mimes`, `image`, `exists`, `unique`, and nested array (`*`) rules.
5. SQL injection through query builder and Eloquent methods that bind values: `where`, `whereIn`, `whereJsonContains`,
   JSON path (`->`) selectors, `orderBy` direction, `upsert`, and the grammars in `Hypervel\Database\Query\Grammars`.
6. XSS through Blade's escaped output (`{{ }}`, `@json`, component attributes), `Hypervel\Support\Js`,
   `HtmlString` misuse inside the framework, and the rendered Markdown mail and notification templates.
7. Path traversal and file handling in `Hypervel\Filesystem`, `Hypervel\Http\UploadedFile`, and the local
   disk's temporary and signed URLs.
8. Request parsing in `Hypervel\Http\Request`: host, scheme, and IP detection behind proxies, and content
   negotiation.
9. Cross-request data or authorization leaks through shared state in `Hypervel\Container`,
   `Hypervel\Context`, `Hypervel\Coroutine`, or pooled database and Redis connections. Include cleanup and
   cancellation paths where one operation can leave state visible to another.

Lower priority or out of scope:

- `vendor/`. Symfony, Flysystem, Monolog, and other dependencies have their own security processes. Report an issue
  here only when Hypervel uses a dependency in a way that creates the vulnerability.
- `tests/`, `types/`, `bin/`, and `src/testing` (test-only helpers that do not run in production).
- `src/foundation/resources/exceptions` and other output that only appears when `APP_DEBUG=true`.
- `Hypervel\Console` and Artisan commands, because only operators can run them.

## How to exercise it

- Dependencies are installed in `/src/vendor`. Run a test file with `vendor/bin/phpunit --no-progress <path>`.
  For multiple files or the full suite, use `composer test:parallel -- --processes=4`, optionally selecting a
  directory, for example `composer test:parallel -- --processes=4 tests/Encryption`.
- `Hypervel\Testbench\TestCase` boots a full application. A test that sends a request with
  `$this->get()` or `$this->post()` is the preferred reproducer for request-handling issues.
- Testbench defaults to in-memory SQLite. MySQL, MariaDB and PostgreSQL servers are not available in the container.
- Run `hypervel-services-start` before tests. Redis integration tests use the local server and separate databases
  for parallel workers; keep the four-worker limit above. Other external-service tests skip when not configured.
- Tests run in coroutines by default. To reproduce a state leak, interleave concurrent coroutines with a real
  yield and show the data or authorization crossing between them. Test cleanup and cancellation when relevant.
- PHPStan analyzer tests use `composer test:phpstan`; Testbench package tests use `composer test:testbench`.
- Tests that need public network access cannot run in the offline container. DNS validation tests use
  `Validator::fakeDnsLookups()` to avoid depending on public DNS.

## How you rate severity

- Critical: unauthenticated remote code execution, authentication bypass, or recovery of `APP_KEY` in a default
  application.
- High: SQL injection through a value-binding API, XSS through escaped Blade output, forging signed URLs, encrypted
  cookies, or CSRF tokens, session fixation, cross-request disclosure of another user's private data, or a validation
  rule bypass that defeats the rule's documented purpose.
- Medium: issues that need a non-default but common configuration, open redirects in framework helpers, cache
  poisoning through the `Host` header, and a denial of service from a single small request (for example, ReDoS or
  unbounded memory use).
- Low: hardening issues with no demonstrated impact.

## How reports and patches should look

- Name the affected class and method, the conditions an application must meet to be affected, and a self-contained
  PHPUnit test that fails before the fix and passes after it.
- Write patches as a `git diff` against `/src`. Follow the existing code style (`.php-cs-fixer.php`) and keep the patch
  as small as possible.

## Anything to leave alone

These behaviors are documented as the application developer's responsibility:

- Raw SQL methods (`DB::raw`, `whereRaw`, `selectRaw`, `orderByRaw`, `DB::statement`, and similar).
- User-controlled column or table names, including `orderBy` columns. The documentation tells developers never to
  pass user input as a column name.
- Unescaped Blade output (`{!! !!}`) and `HtmlString` values that the application creates.
- Mass assignment when a model sets `$guarded = []` or lists sensitive attributes in `$fillable`.
- Anything that needs `APP_KEY`, including remote code execution through `decrypt()` with a known key.
- Information exposed when `APP_DEBUG=true`.
- Missing security headers, framework version disclosure, and username enumeration through timing.
- Volumetric denial of service.
