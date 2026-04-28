# Upstream Sync Guide

This guide governs how Hypervel stays current with upstream packages (Laravel framework, Laravel ecosystem packages, third-party packages). It is the entry point for an LLM running a sync session.

## When this guide applies

When the user asks to run an upstream sync, sync session, upstream review, or similar. This guide **overrides** the `/hypervel-pr` skill's default first-time porting flow — follow the sync workflow below instead.

For the mechanics of porting code (namespace changes, container conversion, service provider migration, listener conversion, type modernization, test porting), `docs/ai/porting.md` is authoritative. Re-read it before writing any code. This guide only covers the *surrounding* workflow — discovery, classification, commit structure, PR structure, state tracking.

## Files in this directory

- **`sync.yaml`** — state file: last reviewed tag and last ported tag per package. Updated during every session. YAML (not markdown) because raw-in-IDE readability matters more than GitHub rendering for this one.
- **`<package>.md`** — per-package divergence notes. Created **lazily** when a real divergence is discovered. Never pre-stub empty files. Filename convention: gh repo slug with `/` replaced by `-` (e.g., `laravel-framework.md`, `orchestral-testbench.md`, `spatie-laravel-permission.md`).

## Non-negotiable rules

- **Every session walks every package in `sync.yaml`, top to bottom.** No skipping, no prioritization, no "we'll get to that next time". A package with zero new releases is a 10-second confirmation; a package with many releases is real work. Session length is emergent, not budgeted.
- **Releases are walked one at a time, oldest to newest.** Never merge multiple releases' PRs into one flat list. Finish release N before opening release N+1.
- **Never auto-decide a PR is skippable.** Propose classification, explain reasoning, wait for user approval. The user decides scope; you propose.
- **One commit per upstream PR.** Separation is cheap; bad reverts are expensive.
- **Stop-and-ask rules from `porting.md` apply in full** — source bugs, coroutine/container divergence, unusual dependencies, anything surprising.

## Session workflow

### Step 1 — Read state

Read `sync.yaml` top to bottom. For each package entry, note: the repo slug (top-level key), `last_reviewed_tag`, `last_ported_tag`, and whether the `notes` field references a `<package>.md` divergence doc.

### Step 2 — Process each package

Work through the entries top to bottom. For each package:

**2a. Read divergence notes**

If the package's `notes` field references a `<package>.md` divergence doc, read it in full before proceeding. Skip this step if there is no divergence doc.

**2b. Find new releases**

```
gh release list --repo <repo-slug> --limit 50
```

`gh release list` returns releases in reverse chronological order (newest first). Walk the output downward until you hit the package's `last_reviewed_tag` — every release **above** that line is new. Reverse that subset to get oldest-first processing order. Do not rely on string comparison of tag names; use release order.

If zero new releases, note "no new releases" for the session record and move to the next package.

**2c. Process releases one at a time, oldest first**

For each new release (do not batch):

```
gh release view <tag> --repo <repo-slug>
```

Extract the list of upstream PRs referenced in the release notes.

**2d. Walk each PR in the release**

For each PR:

```
gh pr view <number> --repo <repo-slug>
```

Propose a classification and reasoning:

- **port** — take this change into Hypervel
- **skip** — intentionally not taken (state why: Laravel-Cloud-specific, PHP-FPM lifecycle, already diverged per `<package>.md`, already implemented differently in Hypervel, deprecated upstream path, etc.)
- **defer** — valid but blocked (state what is blocking it and what would unblock)

Wait for user approval on every classification. Never silently skip.

If porting: follow `docs/ai/porting.md` for the mechanics. Commit with:

```
Port <repo-slug>#<pr-number>: <original PR title>
```

Optional commit body only if the port diverges from the upstream approach and the reason needs explaining.

**2e. laravel/framework direct-to-branch commits**

After walking the release's PRs, also scan for commits that landed directly on the branch without a PR — these are not surfaced by release notes:

```
gh api repos/laravel/framework/compare/<last_reviewed_tag>...<new_tag> --jq '.commits[] | select(.commit.message | test("\\(#\\d+\\)") | not) | {sha: .sha, message: .commit.message}'
```

Report each result. Classify port/skip/defer the same way.

This check is **only required for `laravel/framework`**. Other packages release tightly enough that tags cover everything.

**2f. Close out the release**

When every PR (and any direct commits) in the release has been decided and committed/recorded:

- Bump `last_reviewed_tag` in `sync.yaml` to this release's tag.
- Bump `last_ported_tag` **only if** every porting decision for this release is complete (all ports committed, all skips recorded, nothing deferred pending work). If anything is deferred, `last_ported_tag` stays behind `last_reviewed_tag`.

Then move to the next release for the same package.

**2g. Open the package's PR**

Once every new release for this package has been processed, bump `last_session` in `sync.yaml` to today's date, then open a PR for this package using the structure defined under "PR structure" below. The PR contains all the commits you made for this package during this session.

Do this before moving to the next package — the user can review and merge while you continue. If the session is interrupted mid-package, still open the PR with whatever commits landed (see "Partial-session handling" below).

**2h. Move to the next package**

Continue with the next entry in `sync.yaml`. Repeat step 2 until every package has been walked.

## PR structure

**One PR per package per session.** All commits for that package's sync land in the same PR.

**Escape hatch:** if a single upstream PR is large, risky, or architecturally significant (framework-wide refactor, new subsystem, cross-package behavior change), split it into its own dedicated PR. Judgment, not a rule.

### PR body format

Every session PR body must contain:

```markdown
## Releases included
- [<tag>](<release-url>)
- [<tag>](<release-url>)

## PRs ported
- [<repo>#<number>](<pr-url>) — <short description>
- [<repo>#<number>](<pr-url>) — <short description>

## PRs skipped
- [<repo>#<number>](<pr-url>) — <reason>

## PRs deferred
- [<repo>#<number>](<pr-url>) — <reason, and what would unblock>

## Direct-to-branch commits (framework only, if any)
- [<sha>](<commit-url>) — <decision and reasoning>
```

Any section with no entries can be omitted. Keep entries scannable; this body is future-you's record.

## Partial-session handling

If a session is interrupted mid-package:

- `sync.yaml` still reflects whatever releases were fully walked (tags bumped for those).
- The PR is merged with whatever was ported up to that point.
- The next session resumes from the current `last_reviewed_tag`.

Never leave `sync.yaml` in a state that misrepresents what was actually done.

## Per-package divergence notes (`<package>.md`)

Create a divergence note **only** when a real, concrete divergence is discovered that will affect future sync decisions. Contents:

- **What Hypervel does differently** — the actual divergence
- **Why** — the concrete reason (Swoole semantics, architectural decision, deprecated upstream, etc.)
- **Sync implications** — what kinds of upstream PRs to skip or adapt going forward

Never speculate. Never pre-stub. If you find yourself writing a hypothetical, stop.

## Prerequisites

- `gh` CLI must be authenticated (`gh auth status`). If not, stop and ask the user to run `gh auth login` — do not proceed without auth.
- You are running from `contrib/hypervel/components/` (the working directory for this repo).
