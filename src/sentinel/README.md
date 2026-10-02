Sentinel for Hypervel
===

## Differences From Laravel

- The default driver is named `hypervel` and implemented by `Hypervel\Sentinel\Drivers\Hypervel`, instead of Laravel's `laravel` driver.
- The `isRunningOnDockerLocally()` driver helper is not available, and the default driver gives Docker no exception from its public-client check. Running in Docker doesn't show that a request forwarded by a loopback proxy, such as a tunnel agent in the container, came from the local machine. Requests from local and private network addresses are still allowed.

Ported from: https://github.com/laravel/sentinel
