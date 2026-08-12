# gRPC Protocol Buffers Sources

The health schema is copied from `grpc/grpc-proto` revision
`99135b19189588fcc787acb84cff27991787473d`. Its Apache 2.0 license is stored
beside this file. The only schema changes are the PHP namespace generation
options required by this package.

Generation requires the official `protoc` v35.1 release, matching the
`google/protobuf` 5.35 runtime used by the package. Run the complete generation
workflow from the components repository root:

```bash
composer generate:grpc-health
```

The Composer command runs the pinned `protoc`, adds Hypervel's native PHP 8.4
constant types, runs the Composer-locked repository fixer, and then replaces
the generated health classes. The canonical checked-in form is the result of
that complete workflow. The generated `DO NOT EDIT` marker prohibits hand
edits. Native constant types are the only Hypervel typing adaptation; generated
properties and method signatures intentionally remain as `protoc` emits them.

To update the generated health protocol:

1. Copy `grpc/health/v1/health.proto` from the chosen `grpc/grpc-proto`
   revision, retain the Hypervel PHP namespace options, and update the pinned
   revision at the top of this file.
2. When changing the protobuf runtime, update the `google/protobuf` Composer
   requirement through Composer. When changing `protoc`, update the required
   version in `bin/generate-grpc-health.sh` and the version documented above.
3. Run `composer generate:grpc-health` and review the generated changes before
   committing them.

Running `composer generate:grpc-health` a second time must leave the generated
directory unchanged. A protocol, protoc, runtime, or fixer update is accepted
only after `tests/Grpc`, `tests/Integration/Grpc`, both plaintext and TLS
grpc-go client runs, and the repository-wide verification suite remain green.
