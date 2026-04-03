#!/usr/bin/env bash

# Start all test servers for integration testing.
#
# Usage:
#   ./bin/test-servers.sh
#
# Servers:
#   - HTTP server on port 19501
#   - TCP packet server on port 19502
#   - WebSocket server on port 19503
#   - HTTP v2 server on port 19505
#   - Reverb WebSocket server on port 19510
#   - Reverb Redis WebSocket server on port 19511
#
# Press Ctrl+C to stop all servers.

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

# Track child PIDs for cleanup
PIDS=()

cleanup() {
    echo ""
    echo "Stopping test servers..."
    for pid in "${PIDS[@]}"; do
        kill "$pid" 2>/dev/null || true
    done
    # Swoole servers need a moment for graceful shutdown, then force-kill stragglers
    sleep 1
    for pid in "${PIDS[@]}"; do
        kill -9 "$pid" 2>/dev/null || true
    done
    exit 0
}

trap cleanup EXIT

echo "Starting test servers..."

php "$PROJECT_DIR/src/engine/examples/http_server.php" &
PIDS+=($!)
echo "  HTTP server started on port 19501 (PID: $!)"

php "$PROJECT_DIR/src/engine/examples/tcp_packet_server.php" &
PIDS+=($!)
echo "  TCP packet server started on port 19502 (PID: $!)"

php "$PROJECT_DIR/src/engine/examples/websocket_server.php" &
PIDS+=($!)
echo "  WebSocket server started on port 19503 (PID: $!)"

php "$PROJECT_DIR/src/engine/examples/http_server_v2.php" &
PIDS+=($!)
echo "  HTTP v2 server started on port 19505 (PID: $!)"

REVERB_SERVER_PORT=19510 php "$PROJECT_DIR/tests/Integration/Reverb/server.php" &
PIDS+=($!)
echo "  Reverb WebSocket server started on port 19510 (PID: $!)"

# Brief delay so the first Reverb server finishes Bootstrapper::bootstrap()
# before the second starts — they share temp directory paths and race otherwise.
sleep 2

REVERB_SERVER_PORT=19511 REVERB_SCALING_ENABLED=true php "$PROJECT_DIR/tests/Integration/Reverb/server.php" &
PIDS+=($!)
echo "  Reverb Redis WebSocket server started on port 19511 (PID: $!)"

sleep 2

REVERB_SERVER_PORT=19512 REVERB_TEST_WORKER_NUM=2 php "$PROJECT_DIR/tests/Integration/Reverb/server.php" &
PIDS+=($!)
echo "  Reverb multi-worker server started on port 19512 (PID: $!)"

sleep 2

REVERB_SERVER_PORT=19513 REVERB_SCALING_ENABLED=true php "$PROJECT_DIR/tests/Integration/Reverb/server.php" &
PIDS+=($!)
echo "  Reverb scaling server A started on port 19513 (PID: $!)"

sleep 2

REVERB_SERVER_PORT=19514 REVERB_SCALING_ENABLED=true php "$PROJECT_DIR/tests/Integration/Reverb/server.php" &
PIDS+=($!)
echo "  Reverb scaling server B started on port 19514 (PID: $!)"

echo ""
echo "All servers running. Press Ctrl+C to stop."
echo ""

# Wait for all background processes
wait
