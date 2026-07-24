# gRPC Test Protocol Buffers Sources

`test_service.proto` is the shared schema for Hypervel client/server tests and the
independent grpc-go peers. The generated PHP message classes live beside the
schema, while the generated Go messages and service bindings live under
`tests/Integration/Grpc/Interop/testingpb`.

Keep generated fixture proto, message, and service names from ending in `Test`.
Protoc would otherwise create a `*Test.php` file that PHPUnit mistakes for a
test class during recursive suite discovery.

Generation requires `protoc` v35.1, `protoc-gen-go` v1.36.11, and
`protoc-gen-go-grpc` v1.6.2. Install the Go plugins with:

```bash
go install google.golang.org/protobuf/cmd/protoc-gen-go@v1.36.11
go install google.golang.org/grpc/cmd/protoc-gen-go-grpc@v1.6.2
```

Run these commands from the components repository root:

```bash
grpc_php_out="$(mktemp -d)"
trap 'rm -rf "$grpc_php_out"' EXIT

protoc \
  --proto_path=tests/Grpc/Fixtures \
  --php_out="$grpc_php_out" \
  --go_out=tests/Integration/Grpc/Interop \
  --go_opt=module=hypervel.dev/components/grpc-interop \
  --go-grpc_out=tests/Integration/Grpc/Interop \
  --go-grpc_opt=module=hypervel.dev/components/grpc-interop \
  tests/Grpc/Fixtures/test_service.proto

cp \
  "$grpc_php_out/Hypervel/Tests/Grpc/Fixtures/TestReply.php" \
  tests/Grpc/Fixtures/TestReply.php
cp \
  "$grpc_php_out/Hypervel/Tests/Grpc/Fixtures/TestRequest.php" \
  tests/Grpc/Fixtures/TestRequest.php
rsync --archive --delete \
  "$grpc_php_out/Hypervel/Tests/Grpc/Fixtures/Metadata/" \
  tests/Grpc/Fixtures/Metadata/

./vendor/bin/php-cs-fixer fix \
  --config=.php-cs-fixer.php \
  tests/Grpc/Fixtures/TestReply.php \
  tests/Grpc/Fixtures/TestRequest.php \
  tests/Grpc/Fixtures/Metadata/TestService.php
```

`TestServiceClient.php` is the authored generated-style client used to verify
Hypervel's official PHP method-body compatibility. The `ClientCall*` fixtures
exercise call internals and are likewise not generated from the schema.

The canonical checked-in PHP form is the pinned protoc output followed by the
Composer-locked repository fixer. The generated `DO NOT EDIT` marker prohibits
hand edits; regeneration means running both steps. Only the three generated PHP
paths above belong in the targeted fixer command—the other fixture classes are
authored test support.

Running the complete generation, copy, and fixer workflow a second time must
leave both generated trees unchanged. A protoc, Go plugin, or fixer update is
accepted only after `tests/Grpc`, `tests/Integration/Grpc`, both plaintext and
TLS grpc-go client runs, and the repository-wide verification suite remain
green.
