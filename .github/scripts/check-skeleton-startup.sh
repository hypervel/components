#!/usr/bin/env bash

# Run from the installed application's directory. Keep the server bounded even
# if startup fails or the workflow cancels while an HTTP request is in progress.
set -euo pipefail

timeout --kill-after=5s 120s php artisan serve > storage/logs/ci-startup.log 2>&1 &
server_pid=$!

cleanup() {
    status=$?
    trap - EXIT
    kill -TERM "$server_pid" 2>/dev/null || true

    for attempt in {1..50}; do
        if ! kill -0 "$server_pid" 2>/dev/null; then
            break
        fi
        sleep 0.1
    done

    # timeout owns the process group, including the Swoole workers.
    if kill -0 "$server_pid" 2>/dev/null; then
        kill -KILL -- "-$server_pid" 2>/dev/null || true
    fi
    wait "$server_pid" 2>/dev/null || true

    if (( status != 0 )); then
        for log in storage/logs/*.log; do
            if [[ -f "$log" ]]; then
                echo "Startup diagnostics: $log"
                tail -100 "$log"
            fi
        done
    fi

    exit "$status"
}

trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

base_url="http://${SERVER_HOST:?Set SERVER_HOST}:${SERVER_PORT:?Set SERVER_PORT}"
ready=false

for attempt in {1..30}; do
    if ! kill -0 "$server_pid" 2>/dev/null; then
        echo 'The application server exited before becoming ready.' >&2
        exit 1
    fi

    status=$(curl --silent --max-time 2 --output /dev/null --write-out '%{http_code}' "$base_url/up") || status=000
    if [[ "$status" == 200 ]]; then
        ready=true
        break
    fi
    sleep 1
done

if [[ "$ready" != true ]]; then
    echo "The health endpoint did not return HTTP 200: $base_url/up (last status: $status)" >&2
    exit 1
fi

status=$(curl --silent --show-error --max-time 5 --output /dev/null --write-out '%{http_code}' "$base_url/")
if [[ "$status" != 200 ]]; then
    echo "The homepage returned HTTP $status: $base_url/" >&2
    exit 1
fi

echo 'The homepage and health endpoint both returned HTTP 200.'
