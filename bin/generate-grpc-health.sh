#!/usr/bin/env bash

set -euo pipefail

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
required_protoc_version="$("$repository_root/bin/grpc-health-protoc.sh" version)"

cd "$repository_root"

if ! command -v protoc >/dev/null 2>&1; then
    echo "protoc ${required_protoc_version} is required to generate the gRPC health classes." >&2
    exit 1
fi

installed_protoc_version="$(protoc --version)"

if [[ "$installed_protoc_version" != "libprotoc ${required_protoc_version}" ]]; then
    echo "protoc ${required_protoc_version} is required; found ${installed_protoc_version}." >&2
    exit 1
fi

generated_directory="$(mktemp -d)"

cleanup() {
    rm -rf -- "$generated_directory"
}

trap cleanup EXIT

protoc \
    --proto_path=src/grpc/resources/proto \
    --php_out="$generated_directory" \
    src/grpc/resources/proto/grpc/health/v1/health.proto

generated_health_directory="$generated_directory/Hypervel/Grpc/Health/V1"

php src/grpc/resources/proto/type-generated-constants.php \
    "$generated_health_directory"

./vendor/bin/php-cs-fixer fix \
    --config=.php-cs-fixer.php \
    "$generated_health_directory"

rsync --archive --delete \
    "$generated_health_directory/" \
    src/grpc/src/Health/V1/
