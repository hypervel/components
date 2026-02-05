#!/usr/bin/env bash

# Start all engine test servers for integration testing.
#
# Usage:
#   ./bin/test-servers.sh
#
# Servers:
#   - HTTP server on port 19501
#   - TCP packet server on port 19502
#   - WebSocket server on port 19503
#   - HTTP v2 server on port 19505
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
    exit 0
}

trap cleanup EXIT

echo "Starting engine test servers..."

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

echo ""
echo "All servers running. Press Ctrl+C to stop."
echo ""

# Wait for all background processes
wait
