#!/usr/bin/env bash
# Run the component harnesses on both checkouts, alternating their order, three times.
#   components.sh BASELINE CURRENT OUT_DIR smoke|measure|inventory [CPU_LIST]
set -euo pipefail

if [ "$#" -lt 4 ] || [ "$#" -gt 5 ]; then
    echo "Usage: components.sh BASELINE CURRENT OUT_DIR smoke|measure|inventory [CPU_LIST]" >&2
    exit 1
fi
baseline=$(realpath "$1")
current=$(realpath "$2")
out=$(realpath -m "$3")
mode="$4"
harness=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
affinity=()
if [ "$#" -eq 5 ]; then affinity=(taskset -c "$5"); fi
php_flags=(-d opcache.enable_cli=1 -d opcache.jit=disable)

# The argument alone selects the mode; inherited variables must not.
unset BENCH_SMOKE
fetcher_flags=()

case "$mode" in
    smoke)
        export BENCH_SMOKE=1
        fetcher_flags=(--smoke)
        repetitions=1
        ;;
    measure)
        repetitions=3
        ;;
    inventory)
        repetitions=1
        ;;
    *)
        echo "Mode must be smoke, measure or inventory, got: $mode" >&2
        exit 1
        ;;
esac

mkdir -p "$out"

for repetition in $(seq 1 "$repetitions"); do
    if [ $((repetition % 2)) -eq 1 ]; then order="baseline current"; else order="current baseline"; fi

    for side in $order; do
        root=$([ "$side" = baseline ] && echo "$baseline" || echo "$current")

        git -C "$root" rev-parse HEAD > "$out/$side.revision"
        git -C "$root" status --short > "$out/$side.status"
        (cd "$root" && composer show --format=json) > "$out/$side.dependencies.json"

        if [ "$mode" = inventory ]; then
            : > "$out/inventory-$side.jsonl"
            (cd "$root" && INVENTORY_OUT="$out/inventory-$side.jsonl" php "${php_flags[@]}" vendor/bin/phpunit \
                --no-progress "$harness/Fixtures/SentryInstrumentationInventory.php" > "$out/inventory-$side.txt" 2>&1) \
                || { echo "inventory $side failed; see $out/inventory-$side.txt"; exit 1; }
            continue
        fi

        : > "$out/components-$side-r$repetition.jsonl"
        (cd "$root" && BENCH_OUT="$out/components-$side-r$repetition.jsonl" "${affinity[@]}" php "${php_flags[@]}" vendor/bin/phpunit \
            --no-progress "$harness/Fixtures/SentryComponentBenchmark.php" > "$out/components-$side-r$repetition.txt" 2>&1) \
            || { echo "components $side r$repetition failed; see $out/components-$side-r$repetition.txt"; exit 1; }

        "${affinity[@]}" php "${php_flags[@]}" "$harness/request-fetcher.php" --root="$root" "${fetcher_flags[@]}" \
            > "$out/fetcher-$side-r$repetition.txt"

        echo "$side r$repetition done"
    done
done
