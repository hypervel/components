#!/usr/bin/env bash
# Point a skeleton copy at one components checkout through per-package path repositories,
# so only the packages a real split install would have are installed and discovered.
set -euo pipefail

if [ "$#" -ne 4 ]; then
    echo "Usage: setup-app.sh APP_DIRECTORY COMPONENTS_CHECKOUT FRAMEWORK_CHECKOUT SKELETON_CHECKOUT" >&2
    exit 1
fi

app=$(realpath -m "$1")
components=$(realpath "$2")
framework=$(realpath "$3")
skeleton=$(realpath "$4")
harness=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)

if [ -e "$app" ]; then
    echo "Use a new scratch directory; refusing to overwrite $app" >&2
    exit 1
fi

mkdir -p "$app"
git -C "$skeleton" archive HEAD | tar -x -C "$app"
cp -R "$harness/Fixtures/app/." "$app/"

versions=$(php -r '
    $versions = [];
    foreach (glob($argv[1] . "/src/*/composer.json") as $file) {
        $versions[json_decode(file_get_contents($file), true)["name"]] = "0.4.x-dev";
    }
    echo json_encode($versions, JSON_UNESCAPED_SLASHES);
' "$components")

cd "$app"
composer config repositories.framework "{\"type\":\"path\",\"url\":\"$framework\",\"options\":{\"symlink\":true}}"
composer config repositories.components "{\"type\":\"path\",\"url\":\"$components/src/*\",\"options\":{\"symlink\":true,\"versions\":$versions}}"
composer require hypervel/sentry:0.4.x-dev --no-update -n

php -r '
    file_put_contents($argv[1] . "/benchmark.json", json_encode([
        "components" => $argv[2],
        "framework" => $argv[3],
        "skeleton_revision" => $argv[4],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
' "$app" "$components" "$framework" "$(git -C "$skeleton" rev-parse HEAD)"

php "$harness/Fixtures/seed.php" "$app/database/bench.sqlite"
echo "Prepared $app. Install its dependencies separately with Composer; see README.md."
