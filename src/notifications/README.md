Notifications for Hypervel
===

Documentation: https://hypervel.org/docs/notifications

## Differences From Laravel

- Calling `Notification::locale()` during provider registration or boot sets a worker default. Calls after boot apply only to the next send in the current execution. See [notification locales](https://hypervel.org/docs/notifications#localizing-notifications).
- The manager creates a sender for each send so concurrent sends cannot change one another's locale. Laravel's protected `$notificationSender` cache is omitted; override `resolveNotificationSender()` to customize sender creation.

Ported from: https://github.com/laravel/framework/tree/13.x/src/Illuminate/Notifications
