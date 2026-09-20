Session for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/session)

Documentation: https://hypervel.org/docs/session

## Differences From Laravel

- `Store::passwordConfirmed(?string $guard = null)` stamps a guard-scoped key (`auth.password_confirmed_at_{guard}`) instead of Laravel's single shared key, resolving the current guard when none is given.
- Password-hash session artifacts are HMAC-only. Laravel's raw-hash fallbacks for legacy sessions and guards without `hashPasswordForCookie()` are intentionally omitted. Custom guards used with `auth.session` must provide that method; extending `SessionGuard` supplies it.
- Hypervel's Redis session driver persists directly through Redis instead of Laravel's shared cache-backed handler. Laravel's APC, Memcached, DynamoDB, and shared cache-wrapper session drivers are not provided.
- Database sessions resolve connections by name in the current coroutine. Laravel's `SessionManager::getDatabaseConnection()` hook is not provided.
- Hypervel's generated sessions table uses a nullable indexed string for `user_id`, supporting integer, UUID, ULID, and application-defined identifiers, together with a nullable `auth_provider` for provider-qualified ownership. Its `ip_address` uses the semantic IP column type, including PostgreSQL's native `inet` type.
- `DatabaseSessionHandler::getDefaultPayload()` receives the session ID before the serialized data. Laravel's scalar `addUserInformation()` and `userId()` hooks are not provided because Hypervel stores the authentication provider and user ID as one ownership value.
