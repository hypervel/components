#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TEMPORARY_DIR="$(mktemp -d)"

cleanup() {
    rm -rf "$TEMPORARY_DIR"
}

trap cleanup EXIT

openssl req -x509 -newkey rsa:2048 -sha256 -nodes \
    -keyout "$TEMPORARY_DIR/ca.key" \
    -out "$SCRIPT_DIR/ca.crt" \
    -days 3650 \
    -subj "/CN=Hypervel HTTP Test CA"

openssl req -new -newkey rsa:2048 -sha256 -nodes \
    -keyout "$SCRIPT_DIR/server.key" \
    -out "$TEMPORARY_DIR/server.csr" \
    -subj "/CN=localhost"

openssl x509 -req \
    -in "$TEMPORARY_DIR/server.csr" \
    -CA "$SCRIPT_DIR/ca.crt" \
    -CAkey "$TEMPORARY_DIR/ca.key" \
    -CAserial "$TEMPORARY_DIR/ca.srl" \
    -CAcreateserial \
    -out "$SCRIPT_DIR/server.crt" \
    -days 3650 \
    -sha256 \
    -extfile <(printf '%s\n' \
        "basicConstraints=critical,CA:FALSE" \
        "keyUsage=critical,digitalSignature,keyEncipherment" \
        "extendedKeyUsage=serverAuth" \
        "subjectAltName=DNS:localhost,IP:127.0.0.1")

openssl req -new -newkey rsa:2048 -sha256 -nodes \
    -keyout "$SCRIPT_DIR/client.key" \
    -out "$TEMPORARY_DIR/client.csr" \
    -subj "/CN=Hypervel HTTP Test Client"

openssl x509 -req \
    -in "$TEMPORARY_DIR/client.csr" \
    -CA "$SCRIPT_DIR/ca.crt" \
    -CAkey "$TEMPORARY_DIR/ca.key" \
    -CAserial "$TEMPORARY_DIR/ca.srl" \
    -out "$SCRIPT_DIR/client.crt" \
    -days 3650 \
    -sha256 \
    -extfile <(printf '%s\n' \
        "basicConstraints=critical,CA:FALSE" \
        "keyUsage=critical,digitalSignature,keyEncipherment" \
        "extendedKeyUsage=clientAuth")

openssl req -x509 -newkey rsa:2048 -sha256 -nodes \
    -keyout "$TEMPORARY_DIR/untrusted-ca.key" \
    -out "$SCRIPT_DIR/untrusted-ca.crt" \
    -days 3650 \
    -subj "/CN=Untrusted Hypervel HTTP Test CA"

chmod 600 "$SCRIPT_DIR/server.key" "$SCRIPT_DIR/client.key"
chmod 644 "$SCRIPT_DIR/ca.crt" "$SCRIPT_DIR/server.crt" "$SCRIPT_DIR/client.crt" "$SCRIPT_DIR/untrusted-ca.crt"
