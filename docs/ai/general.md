# General Code Style

## Coroutine Context Keys

Format: `__package_name.segment.segment`

- `__` prefix on all framework keys — reserves the namespace so user keys never collide
- First segment = owning package in `snake_case` (kebab-case dirs become `snake_case`: `nested-set` → `nested_set`)
- `snake_case` throughout, never `camelCase`
- Store as `protected const` when used in multiple places within a class
