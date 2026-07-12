# Framework Coroutine, State, and Lifecycle Audit Ledger

## Purpose

This companion document stores the durable findings and implementation history produced by the [framework audit plan](2026-07-12-framework-coroutine-state-lifecycle-audit.md). The operating procedure, active-work routing, cross-package dependency index, and 71-package checklist remain in the main plan so they can be reread after compaction without loading this growing history.

Append package entries in checklist order. Keep each entry compact but complete enough to recover the final source-backed decision without chat history.

## Reading and writing rules

- Do not reread this file in full after compaction.
- Read the active package's entry, if one exists, and only the shared/package entries named by the main plan's routing and dependency indexes.
- Do not record proposed findings before second-opinion consensus.
- Do not preserve discussion history, discarded drafts, or superseded designs.
- Record an important rejected concern only when a future auditor could reasonably rediscover it and repeat the same investigation.
- Give every shared finding one owning ID and use that ID in every affected package entry.
- Keep the main plan's routing and dependency indexes sufficient to locate every entry required by active or future work.
- Record implementation commit hashes after those commits exist. Identify the final ledger/checklist bookkeeping commit by subject; it cannot contain its own final hash.

## Entry templates

### Clean package

```md
### `{package}`

- **Architecture and inspected risk surfaces:** concise package lifetime/ownership model and the high-risk files, bindings, traits, callers, and tests inspected.
- **Result:** no verified defect or approved improvement.
- **Important rejected concerns:** concise source-backed reasons for any non-obvious safe pattern.
- **Cross-package notes:** dependencies or consumers that need later revalidation, or “none”.
- **Validation and review:** audit review sign-off, executable gates omitted because code did not change, and owner pre-commit approval.
- **Commits:** audit-bookkeeping commit subject.
- **Assessment:** coroutine safety, worker-state safety, lifecycle, performance, and complexity in one concise statement.
```

### Package with findings

```md
### `{package}`

- **Architecture and inspected risk surfaces:** concise package lifetime/ownership model and the high-risk files, bindings, traits, callers, and tests inspected.

| ID | Category | Severity | Confidence | Failure and owning boundary | Final decision |
|---|---|---|---|---|---|
| `{package}-01` | Defect | Major | High | Concrete failure schedule; lowest owner | Consensus correction |

- **Important rejected concerns:** concerns whose rejection prevents repeated speculative work.
- **Cross-package implications:** owning and affected package IDs, including required revalidation.
- **Implementation:** final source/test/doc changes and stale design removed.
- **Regression tests:** deterministic old-failure coverage and relevant integration coverage.
- **Performance and complexity:** measured/reasoned overhead and why the result is proportionate.
- **Validation and review:** focused commands, `composer fix`, self-review, code-review sign-off, and owner pre-commit approval.
- **Commits:** ordered implementation commit hashes and subjects; audit-bookkeeping commit subject.
- **Assessment:** final coroutine safety, worker-state safety, lifecycle, performance, robustness, and overengineering judgment.
```

### Shared work

```md
### Shared finding `{owner-package}-NN`: {title}

- **Owner:** package and exact lower-level contract.
- **Affected packages:** bidirectional list of every consumer/sibling changed or revalidated.
- **Failure:** realistic schedule and visible effect.
- **Decision:** final coherent cross-package design.
- **Implementation and cleanup:** source/tests/docs removed or changed across packages.
- **Validation:** package-focused coverage, full gate, self-review, review sign-off, and owner pre-commit approval.
- **Commits:** ordered implementation hashes and subjects; audit-bookkeeping commit subject.
- **Revalidation:** completed package entries updated by this contract change.
```

## Package findings and changes

No packages have been completed under this audit yet.
