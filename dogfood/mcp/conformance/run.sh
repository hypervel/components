#!/usr/bin/env bash
set -uo pipefail

CONFORMANCE="${CONFORMANCE_PACKAGE:-@modelcontextprotocol/conformance@alpha}"
REVISION="${CONFORMANCE_REVISION:-2026-07-28}"
PORT="${CONFORMANCE_PORT:-8001}"

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$DIR/.." && pwd)"
SUITE="${1:-all}"

CONFORMANCE_OUTPUT_DIR="${CONFORMANCE_OUTPUT_DIR:-$(mktemp -d "${TMPDIR:-/tmp}/hypervel-mcp-conformance.XXXXXX")}" || exit 1
export CONFORMANCE_OUTPUT_DIR
if [[ -d "$CONFORMANCE_OUTPUT_DIR" && -n "$(find -H "$CONFORMANCE_OUTPUT_DIR" -mindepth 1 -maxdepth 1 -print -quit)" ]]; then
    printf 'Conformance output directory must be empty: %s\n' "$CONFORMANCE_OUTPUT_DIR" >&2
    exit 1
fi
mkdir -p "$CONFORMANCE_OUTPUT_DIR/logs" || exit 1
printf 'Conformance output: %s\n' "$CONFORMANCE_OUTPUT_DIR"

status=0

run_server() {
    if php -r '$socket = @stream_socket_client($argv[1], $errorCode, $errorMessage, 1); if ($socket === false) { exit(1); } fclose($socket);' "tcp://127.0.0.1:$PORT"; then
        printf 'Conformance port is already in use: %s\n' "$PORT" >&2
        exit 1
    fi

    APP_URL="http://127.0.0.1:$PORT" php "$ROOT/vendor/bin/testbench" serve --host=127.0.0.1 --port="$PORT" >"$CONFORMANCE_OUTPUT_DIR/logs/serve.log" 2>&1 &
    local pid=$!
    trap 'kill "$pid" 2>/dev/null; wait "$pid" 2>/dev/null' EXIT
    local ready=false

    for _ in $(seq 1 30); do
        if ! kill -0 "$pid" 2>/dev/null; then
            break
        fi
        # Probe discovery independently of the protocol revision selected for the suite.
        if curl --connect-timeout 1 --max-time 2 -sf -o /dev/null "http://127.0.0.1:$PORT/conformance" -X POST \
            -H 'Content-Type: application/json' \
            -H 'MCP-Protocol-Version: 2026-07-28' \
            -H 'MCP-Method: server/discover' \
            -d '{"jsonrpc":"2.0","id":1,"method":"server/discover","params":{"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientCapabilities":{}}}}'; then
            ready=true
            break
        fi
        sleep 1
    done

    if [[ "$ready" != true ]] || ! kill -0 "$pid" 2>/dev/null; then
        printf 'Conformance server did not become ready; see %s/logs/serve.log\n' "$CONFORMANCE_OUTPUT_DIR" >&2
        exit 1
    fi

    npx --yes "$CONFORMANCE" server \
        --url "http://127.0.0.1:$PORT/conformance" \
        --requirements "$REVISION" \
        --expected-failures "$DIR/conformance-baseline.yml" \
        --output-dir "$CONFORMANCE_OUTPUT_DIR/results/server" || status=1

    kill "$pid" 2>/dev/null
    wait "$pid" 2>/dev/null
    trap - EXIT

    php "$DIR/score.php" server "$CONFORMANCE_OUTPUT_DIR" || status=1
}

run_client() {
    npx --yes "$CONFORMANCE" client \
        --command "php \"$DIR/client.php\"" \
        --requirements "$REVISION" \
        --expected-failures "$DIR/conformance-baseline.yml" \
        --output-dir "$CONFORMANCE_OUTPUT_DIR/results/client" || status=1

    php "$DIR/score.php" client "$CONFORMANCE_OUTPUT_DIR" || status=1
}

case "$SUITE" in
    server) run_server ;;
    client) run_client ;;
    all) run_server; run_client ;;
    *) echo "Usage: run.sh [server|client|all]" >&2; exit 1 ;;
esac

exit $status
