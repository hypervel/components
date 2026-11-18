#!/usr/bin/env bash

# Run Redis or Valkey integration tests in standalone or cluster mode.
# Package suites in tests/Integration/<Package>/Redis are discovered automatically.
# Configure the test service first; arguments after the mode go to the test runner.

set -euo pipefail

mode=${1:-}
case "$mode" in
    standalone)
        # CI reserves database 1 for tests that switch databases, leaving 15 for workers.
        runner=(vendor/bin/paratest --max-processes=15)
        ;;
    cluster)
        # Cluster only supports database zero, so tests must run serially.
        runner=(vendor/bin/phpunit --no-progress)
        ;;
    *)
        echo "Usage: $0 <standalone|cluster> [test runner options]" >&2
        exit 2
        ;;
esac
shift

if (( $# )); then
    # A local filter may select tests in only one of the package suites.
    runner+=("$@" --do-not-fail-on-empty-test-suite)
else
    runner+=(--fail-on-empty-test-suite)
fi

cd "$(dirname "${BASH_SOURCE[0]}")/.."

run_suite() {
    printf 'Running %s\n' "$*"
    "${runner[@]}" "$@"
}

for package in tests/Integration/*; do
    case "$package" in
        tests/Integration/Cache) CACHE_STORE=redis run_suite "$package" ;;
        tests/Integration/Horizon) run_suite "$package" ;;
        tests/Integration/Queue) QUEUE_CONNECTION=redis run_suite "$package" ;;
        *)
            if [[ -d "$package/Redis" ]]; then
                run_suite "$package/Redis"
            fi
            ;;
    esac
done

if [[ "$mode" == standalone ]]; then
    CACHE_STORE=redis run_suite tests/Permission
    run_suite tests/Integration/Redis
else
    # Run only the core tests that support Cluster. The others require standalone Redis.
    run_suite \
        tests/Integration/Redis/ConcurrencyLimiterIntegrationTest.php \
        tests/Integration/Redis/DurationLimiterIntegrationTest.php \
        tests/Integration/Redis/EvalWithShaCacheIntegrationTest.php \
        tests/Integration/Redis/RedisClusterIntegrationTest.php \
        tests/Integration/Redis/RedisConnectionTest.php \
        tests/Integration/Redis/RedisEventsIntegrationTest.php \
        tests/Integration/Redis/RedisPoolHeartbeatIntegrationTest.php \
        tests/Integration/Redis/RedisPoolTeardownLifecycleTest.php \
        tests/Integration/Redis/RedisSubscribeIntegrationTest.php \
        tests/Integration/Redis/SafeScanIntegrationTest.php
fi
