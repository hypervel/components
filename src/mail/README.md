Mail for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/mail)

Documentation: https://hypervel.org/docs/mail

## Differences From Laravel

Hypervel omits Laravel's legacy `Attachment::fromCloudStorage()` helper. Use `Attachment::fromStorageDisk(...)` with a named disk instead.

Hypervel supports Amazon SES through SES v2 only. The `ses` mailer uses the `ses-v2` transport.

Ported from: https://github.com/laravel/framework
