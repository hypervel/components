MCP for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/mcp)

Documentation: https://hypervel.org/docs/mcp

## Differences From Laravel

- HTTP servers validate browser origins against `app.url` and `mcp.allowed_origins`. Configure additional origins for direct cross-origin browser clients; see [allowed origins](https://hypervel.org/docs/mcp#allowed-origins).
- Modern HTTP streams cancel their work when the client disconnects; legacy streams finish. Account for cancellation when performing streamed side effects; see [streaming responses](https://hypervel.org/docs/mcp#streaming-responses).
- Servers do not negotiate protocol revision `2025-03-26`, which Laravel accepts in the `initialize` handshake without supporting the JSON-RPC batching it requires. Clients requesting it are offered `2025-11-25`, so use clients that support `2025-06-18` or newer; see [protocol versions](https://hypervel.org/docs/mcp#protocol-versions).
- Dynamic OAuth registration is rate limited by Passport's `passport` limiter. Customize that limiter when needed; see [OAuth authentication](https://hypervel.org/docs/mcp#oauth).
- Local authorization-server discovery belongs to Passport and requires its configured HTTPS issuer. MCP keeps protected-resource discovery and uses `passport.clients.register` for registration. Choose one registration endpoint and define resource audiences in Passport; see [OAuth setup](https://hypervel.org/docs/mcp#oauth).
- Anonymous registration persists an explicit scope ceiling from Passport's anonymous allowance plus `mcp:use`; custom client schemas require `scopes` and `third_party`. Wildcard callback domains permit HTTPS and HTTP loopback only; explicit administrator allowances and configured native schemes remain supported. See [registration policy](https://hypervel.org/docs/mcp#oauth).
- Connect URLs containing `resource_metadata` or `scope` must be signed with `redirect()->signedRoute()` or `URL::signedRoute()` to prevent discovery-hint tampering; see [authorization challenges](https://hypervel.org/docs/mcp#client-oauth-scopes).
- OAuth clients require an explicit policy allowance for private DNS destinations and a DNS substitute for unresolved hostnames in faked tests. See [OAuth destinations](https://hypervel.org/docs/mcp#oauth-destinations) and [testing OAuth clients](https://hypervel.org/docs/mcp#testing-oauth).
- OAuth clients use the remote server's advertised scopes instead of assuming `mcp:use`. Set `withOAuth(scope: 'mcp:use')` when that scope is required but not advertised; see [OAuth scopes](https://hypervel.org/docs/mcp#client-oauth-scopes).
- The Inspector verifies HTTPS certificates. Configure `NODE_EXTRA_CA_CERTS` for locally issued certificates; see [MCP Inspector](https://hypervel.org/docs/mcp#mcp-inspector).
- `LastModified` attributes require a complete date-time with seconds and a timezone so TypeScript-SDK clients can consume the response. Reformat other timestamp representations as described in [resource annotations](https://hypervel.org/docs/mcp#resource-annotations).
- Subclasses redeclaring `JsonRpcException::$data` or the client `StdioTransport::$input` must match their updated property types; see the [MCP porting guide](https://hypervel.org/docs/porting-from-laravel#mcp).

Ported from: https://github.com/laravel/mcp
