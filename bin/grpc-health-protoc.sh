#!/usr/bin/env bash

set -euo pipefail

protoc_version="35.1"
linux_x86_64_sha256="6930ebf62bd4ea607b98fff052596c6ee564b9835b4ce172c75a3f53ae9d91b7"

case "${1:-}" in
    version)
        if [[ "$#" -ne 1 ]]; then
            echo "Usage: $0 version" >&2
            exit 1
        fi

        echo "$protoc_version"
        ;;
    install)
        if [[ "$#" -ne 2 ]]; then
            echo "Usage: $0 install <destination>" >&2
            exit 1
        fi

        if [[ "$(uname -s)-$(uname -m)" != "Linux-x86_64" ]]; then
            echo "Automatic protoc installation is supported only on Linux x86_64." >&2
            exit 1
        fi

        temporary_directory="$(mktemp -d)"

        cleanup() {
            rm -rf -- "$temporary_directory"
        }

        trap cleanup EXIT

        archive="$temporary_directory/protoc.zip"

        curl --fail --location --silent --show-error \
            --output "$archive" \
            "https://github.com/protocolbuffers/protobuf/releases/download/v${protoc_version}/protoc-${protoc_version}-linux-x86_64.zip"
        echo "${linux_x86_64_sha256}  ${archive}" | sha256sum -c -
        unzip -q "$archive" -d "$temporary_directory/protoc"
        install "$temporary_directory/protoc/bin/protoc" "$2"
        ;;
    *)
        echo "Usage: $0 {version|install <destination>}" >&2
        exit 1
        ;;
esac
