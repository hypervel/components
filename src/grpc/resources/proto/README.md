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
constant types, runs the repository fixer, and then replaces the generated
health classes. The canonical checked-in form is the result of that complete
workflow. The generated `DO NOT EDIT` marker prohibits hand edits. Native
constant types are the only Hypervel typing adaptation; generated properties
and method signatures intentionally remain as `protoc` emits them.

The scheduled `sync-grpc-health-protocol` workflow checks the canonical schema
each week and opens a pull request only when the schema content changes. The
recorded revision advances only with that content, so neither unrelated
`grpc/grpc-proto` commits nor content-identical `health.proto` commits change
the pin or produce a pull request. Run the same synchronization manually from
the repository root:

```bash
composer sync:grpc-health
```

The sync command finds the latest commit that changed `health.proto`, downloads
that revision, retains the Hypervel PHP namespace options, updates the recorded
revision, and runs the complete generation workflow. If the downloaded schema
matches the committed source, it leaves every file unchanged. The workflow
verifies the synchronized package before it opens a pull request.

An upstream change that breaks parity with Hypervel's authored health API, such
as an added or removed RPC, fails the scheduled run instead of opening a pull
request. Run `composer sync:grpc-health` locally, update the health client,
service, route stub, tests, and documentation, and submit the complete change as
a normal pull request. When the workflow does open a pull request, review the
upstream schema and every generated change before merging it.

The `protoc` version and verified archive checksum have one executable source in
`bin/grpc-health-protoc.sh`. The generation script reads its required version
from that helper, and the synchronization workflow uses the helper to install
the compiler.

Compiler and runtime updates are separate from protocol synchronization. When
changing the protobuf runtime, update the `google/protobuf` Composer requirement
through Composer. When changing `protoc`, update the version and checksum in
`bin/grpc-health-protoc.sh` and the version documented above.

Running `composer generate:grpc-health` a second time must leave the generated
directory unchanged. A protocol, protoc, runtime, or fixer update is accepted
only after `tests/Grpc`, `tests/Integration/Grpc`, both plaintext and TLS
grpc-go client runs, and the repository-wide verification suite remain green.
