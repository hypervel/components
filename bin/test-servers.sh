#!/usr/bin/env bash

# Start test servers for integration testing.
#
# Usage:
#   ./bin/test-servers.sh              # Start all servers
#   ./bin/test-servers.sh engine       # Start engine servers only
#   ./bin/test-servers.sh reverb       # Start Reverb servers only
#   ./bin/test-servers.sh engine reverb # Start both groups
#
# Groups:
#   engine  — HTTP (19501), TCP (19502), WebSocket (19503), HTTP v2 (19505)
#   reverb  — Single-worker (19510), Redis scaling (19511), multi-worker (19512),
#             cross-server A (19513), cross-server B (19514),
#             scaling+multi-worker (19515)
#
# Press Ctrl+C to stop all servers.

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

# Track child PIDs for cleanup (these are session leaders via setsid)
PIDS=()
SIGNAL_RECEIVED=0

cleanup() {
    # One-shot guard — prevent double-run from INT+EXIT
    trap - INT TERM EXIT

    echo ""
    echo "Stopping test servers..."
    rm -f /tmp/test-servers-ready

    # Send SIGTERM to each server's process group (negative PID).
    # setsid makes each server a session leader, so -$pid targets
    # the master process and all its worker children.
    for pid in "${PIDS[@]}"; do
        kill -TERM -- "-$pid" 2>/dev/null || true
    done

    # Grace period for Swoole's graceful shutdown path.
    # Must be longer than server.settings.max_wait_time (default 3s).
    sleep 5

    for pid in "${PIDS[@]}"; do
        kill -9 -- "-$pid" 2>/dev/null || true
    done

    # Only force exit 0 for intentional signal shutdown (Ctrl+C).
    # On error (set -e), let the original exit status propagate.
    if [ "$SIGNAL_RECEIVED" -eq 1 ]; then
        exit 0
    fi
}

trap 'SIGNAL_RECEIVED=1; cleanup' INT TERM
trap cleanup EXIT

# Wait for a server to respond on a given port before continuing.
# Polls every second for up to 30 seconds.
wait_for_server() {
    local port=$1
    local label=$2
    local max=30

    for i in $(seq 1 $max); do
        if curl -sf "http://127.0.0.1:${port}/up" > /dev/null 2>&1; then
            return 0
        fi
        sleep 1
    done

    echo "ERROR: ${label} on port ${port} failed to start within ${max}s"
    exit 1
}

start_engine() {
    echo "Starting engine test servers..."

    setsid php "$PROJECT_DIR/src/engine/examples/http_server.php" &
    PIDS+=($!)
    echo "  HTTP server started on port 19501 (PID: $!)"

    setsid php "$PROJECT_DIR/src/engine/examples/tcp_packet_server.php" &
    PIDS+=($!)
    echo "  TCP packet server started on port 19502 (PID: $!)"

    setsid php "$PROJECT_DIR/src/engine/examples/websocket_server.php" &
    PIDS+=($!)
    echo "  WebSocket server started on port 19503 (PID: $!)"

    setsid php "$PROJECT_DIR/src/engine/examples/http_server_v2.php" &
    PIDS+=($!)
    echo "  HTTP v2 server started on port 19505 (PID: $!)"
}

start_reverb() {
    echo "Starting Reverb test servers..."

    # Reverb servers are started serially with readiness checks.
    # They share Bootstrapper temp paths and race if started concurrently.

    setsid sh -c "REVERB_SERVER_PORT=19510 exec php '$PROJECT_DIR/tests/Integration/Reverb/server.php'" &
    PIDS+=($!)
    echo "  Reverb server starting on port 19510 (PID: $!)..."
    wait_for_server 19510 "Reverb"

    setsid sh -c "REVERB_SERVER_PORT=19511 REVERB_SCALING_ENABLED=true exec php '$PROJECT_DIR/tests/Integration/Reverb/server.php'" &
    PIDS+=($!)
    echo "  Reverb Redis server starting on port 19511 (PID: $!)..."
    wait_for_server 19511 "Reverb Redis"

    setsid sh -c "REVERB_SERVER_PORT=19512 REVERB_TEST_WORKER_NUM=2 exec php '$PROJECT_DIR/tests/Integration/Reverb/server.php'" &
    PIDS+=($!)
    echo "  Reverb multi-worker server starting on port 19512 (PID: $!)..."
    wait_for_server 19512 "Reverb multi-worker"

    setsid sh -c "REVERB_SERVER_PORT=19513 REVERB_SCALING_ENABLED=true exec php '$PROJECT_DIR/tests/Integration/Reverb/server.php'" &
    PIDS+=($!)
    echo "  Reverb scaling server A starting on port 19513 (PID: $!)..."
    wait_for_server 19513 "Reverb scaling A"

    setsid sh -c "REVERB_SERVER_PORT=19514 REVERB_SCALING_ENABLED=true exec php '$PROJECT_DIR/tests/Integration/Reverb/server.php'" &
    PIDS+=($!)
    echo "  Reverb scaling server B starting on port 19514 (PID: $!)..."
    wait_for_server 19514 "Reverb scaling B"

    setsid sh -c "REVERB_SERVER_PORT=19515 REVERB_SCALING_ENABLED=true REVERB_TEST_WORKER_NUM=2 exec php '$PROJECT_DIR/tests/Integration/Reverb/server.php'" &
    PIDS+=($!)
    echo "  Reverb scaling+multi-worker server starting on port 19515 (PID: $!)..."
    wait_for_server 19515 "Reverb scaling+multi-worker"
}

# Parse arguments — no args means start everything
SERVER_GROUPS=("$@")

if [ ${#SERVER_GROUPS[@]} -eq 0 ]; then
    SERVER_GROUPS=("engine" "reverb")
fi

for group in "${SERVER_GROUPS[@]}"; do
    case "$group" in
        engine) start_engine ;;
        reverb) start_reverb ;;
        *) echo "Unknown group: $group (available: engine, reverb)"; exit 1 ;;
    esac
done

# Signal readiness for CI — the sentinel file tells callers all servers are up
touch /tmp/test-servers-ready

echo ""
echo "All servers running. Press Ctrl+C to stop."
echo ""

# Wait for all background processes
wait
