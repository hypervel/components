Signal for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/signal)

Ported from: https://github.com/hyperf/hyperf/tree/master/src/signal

## Signal Lifecycle

Configured signal handlers are resolved when a coroutine-enabled worker or
custom process starts and remain shared for that process incarnation. The same
handler instance can be invoked concurrently when it listens for different
signals, so handlers must not retain coroutine-specific mutable state on the
instance.

Swoole owns normal worker shutdown. Registering an application handler for
`SIGTERM` or `SIGINT` consumes that signal through the custom handler instead of
Swoole's native shutdown path, so the handler must explicitly provide the
required shutdown behavior. Processes without coroutine support do not start
Signal watchers.
