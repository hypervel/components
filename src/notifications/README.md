Notifications for Hypervel
===

Documentation: https://hypervel.org/docs/notifications

## Differences From Laravel

Hypervel preserves Slack select option values verbatim instead of lowercasing and stripping characters.
Hypervel also validates Slack's published Block Kit limits before delivery and counts them in characters rather than bytes.

Ported from:

- https://github.com/laravel/framework/tree/13.x/src/Illuminate/Notifications
- https://github.com/laravel/slack-notification-channel
