# gRPC Protocol Buffers Sources

The health schema is copied from `grpc/grpc-proto` revision
`99135b19189588fcc787acb84cff27991787473d`. Its Apache 2.0 license is stored
beside this file. The only schema changes are the PHP namespace generation
options required by this package.

Generation requires the official `protoc` v35.1 release, matching the
`google/protobuf` 5.35 runtime used by the package. Run this command from the
components repository root:

```bash
grpc_health_out="$(mktemp -d)"
trap 'rm -rf "$grpc_health_out"' EXIT

protoc \
  --proto_path=src/grpc/resources/proto \
  --php_out="$grpc_health_out" \
  src/grpc/resources/proto/grpc/health/v1/health.proto

rsync --archive --delete \
  "$grpc_health_out/Hypervel/Grpc/Health/V1/" \
  src/grpc/src/Health/V1/

./vendor/bin/php-cs-fixer fix \
  --config=.php-cs-fixer.php \
  src/grpc/src/Health/V1
```

The canonical checked-in form is the pinned protoc output followed by the
Composer-locked repository fixer. The generated `DO NOT EDIT` marker prohibits
hand edits; regeneration means running both steps above.

Running the complete protoc, copy, and fixer workflow a second time must leave
the generated directory unchanged. A protoc or fixer update is accepted only
after `tests/Grpc`, `tests/Integration/Grpc`, both plaintext and TLS grpc-go
client runs, and the repository-wide verification suite remain green.
