MCP for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/mcp)

Documentation: https://hypervel.org/docs/mcp

## Differences From Laravel

- HTTP servers validate browser origins against `app.url` and `mcp.allowed_origins`. Configure additional origins for direct cross-origin browser clients; see [allowed origins](https://hypervel.org/docs/mcp#allowed-origins).
- Modern HTTP streams cancel their work when the client disconnects; legacy streams finish. Account for cancellation when performing streamed side effects; see [streaming responses](https://hypervel.org/docs/mcp#streaming-responses).
- Dynamic OAuth registration is rate limited by Passport's `passport` limiter. Customize that limiter when needed; see [OAuth authentication](https://hypervel.org/docs/mcp#oauth).
- `LastModified` attributes require a complete date-time with seconds and a timezone so TypeScript-SDK clients can consume the response. Reformat other timestamp representations as described in [resource annotations](https://hypervel.org/docs/mcp#resource-annotations).

Ported from: https://github.com/laravel/mcp
