Slack Notification Channel for Hypervel
===

Documentation: https://hypervel.org/docs/notifications

## Differences From Laravel

- Slack select option values are preserved exactly, rather than lowercased and stripped of characters, so distinct values remain distinct. Interaction handlers should match the original values.

Ported from: https://github.com/laravel/slack-notification-channel
