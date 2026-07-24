Engine for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/engine)

Ported from: https://github.com/hyperf/engine

## Architecture

Hypervel supports Swoole only. Its HTTP, WebSocket, and Reverb servers use the Swoole-specific request and response bridges from `hypervel/http-server`; Hyperf's interchangeable Swoole/Swow HTTP server and response-emitter portability layer is intentionally not part of Engine.
