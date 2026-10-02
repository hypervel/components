Socialite for Hypervel
===

Documentation: https://hypervel.org/docs/socialite

Differences From Laravel
---

- OAuth 1.0 and the legacy `twitter` drivers are not supported. Use the OAuth 2.0 `x` driver, configured under `services.x`; Laravel's `services.x-oauth-2` fallback is not read.
- The facade is `Hypervel\Socialite\Socialite`. Laravel's `Facades\Socialite` alias class is not included.
- Provider instances are cached for the worker lifetime, so each request's provider state lives in coroutine context: the request, `with()` parameters, scopes, PKCE and stateless flags, the redirect URL, and `setConfig()` overrides. Custom providers read this state through getters such as `getRequest()`, `getParameters()`, `getScopes()`, `getClientId()`, `getClientSecret()`, `getRedirectUrl()`, and `getConfig()` instead of Laravel's properties, and there are no `$request`, `$httpClient`, or `$user` properties. See [dynamic provider configuration](https://hypervel.org/docs/socialite#dynamic-provider-configuration).
- Custom OAuth 2.0 providers are built with `buildOAuth2Provider()` instead of `buildProvider()`. They may also use token-response parsers and the generic OpenID Connect base provider. See [custom providers](https://hypervel.org/docs/socialite#custom-providers). The OAuth 1-only `formatConfig()` method is not included.
- OpenID Connect providers may trust additional audiences through `trusted_audiences` while still requiring the configured client ID.
- Invalid ID-token issuers, audiences, and nonces throw `InvalidIssuerException`, `InvalidAudienceException`, and `InvalidNonceException` from `Hypervel\Socialite\Two\Exceptions` instead of Laravel's generic exceptions.
- Google users' raw data does not include Laravel's deprecated `id`, `verified_email`, and `link` aliases. Read `sub`, `email_verified`, and `profile` instead.
- `stateless()` disables OAuth state validation, but the `x` driver's PKCE flow and generic OpenID Connect nonce validation still require session continuity.

Ported from: https://github.com/laravel/socialite
