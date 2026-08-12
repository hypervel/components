Socialite for Hypervel
===

Documentation: https://hypervel.org/docs/socialite

Differences From Laravel
---

- OAuth 1.0 and the legacy `twitter` drivers are not supported. Use the OAuth 2.0 `x` driver instead.
- Custom providers may use `buildOAuth2Provider()`, runtime `setConfig()` overrides, protected request access, token-response parsers, and generic OpenID Connect support. The OAuth 1-only `formatConfig()` method is not included.
- OpenID Connect providers may trust additional audiences through `trusted_audiences` while still requiring the configured client ID.
- `stateless()` disables OAuth state validation, but the `x` driver's PKCE flow and generic OpenID Connect nonce validation still require session continuity.

Ported from: https://github.com/laravel/socialite
