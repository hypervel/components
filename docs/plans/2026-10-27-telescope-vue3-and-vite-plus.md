# Telescope Vue 3 and shared JavaScript tooling

## Objective and delivery

Modernize Telescope's dashboard using Horizon's actual Vue 3 conventions, then consolidate framework JavaScript development under Vite+, pnpm workspaces, and dependency catalogs. Deliver two independently reviewable PRs against `0.4`, in that order. Do not change `0.3` or `main`.

Part 1 was merged and its worktree removed. For Part 2, create a fresh `/home/binaryfire/workspace/contrib/hypervel/components-vite-plus` worktree and branch from updated `origin/0.4`, carrying forward this plan's latest changes. Commit whole files in focused commits with explanatory bodies. Commit, push, open, or merge PRs only when authorized; never squash by default.

Use one implementation checkpoint for the complete Part 2 monorepo refactor. Run focused checks during development, then complete verification and a full self-review before one peer code-review loop through signoff. Do not split packages or tooling steps into separate checkpoints. This does not change the requirement to investigate non-trivial unexpected issues and reach peer consensus before implementing their fixes.

Read this plan, the monorepo root `CLAUDE.md`, and the worktree's `AGENTS.md` after compaction. This plan does not override their approval, verification, source-reading, or dependency-management requirements. No implementation is authorized merely by writing the plan.

Part 1 is merged on both remotes, Telescope's upstream-sync notes are updated, and GitHub closed the retired archive's lockfile alerts. The formatting exception below is approved. Part 2 is published on both remotes. Remaining work: complete the authorized PR review and CI follow-up, merge when green, then perform the post-merge dependency-graph and upstream-sync checks below.

## Maintenance tradeoff and approval items

Both changes address real maintenance costs. Vue 2 constrains Telescope's Vite plugin and retains an unpatched compiler advisory. Retaining Options API, Horizon's patterns, and Telescope's recognizable structure makes future template/lifecycle adaptations straightforward. Request cancellation, routing, and modal behavior still require understanding and tests; upstream patches will not always apply unchanged. A shared toolchain then reduces duplicated dependency decisions, vulnerable lockfile drift, and inconsistent commands across the framework. Use standard Vite+/pnpm behavior first; depart only for a demonstrated requirement.

The owner has approved the following exception:

- **Formatting exception — approved:** permit deterministic Vite+ formatting of maintained first-party frontend source/tests, including ports. Add this approved adaptation to `AGENTS.md`'s Porting Policy during Part 2: “Formatting JavaScript, TypeScript, Vue and frontend CSS sources with the repository's Vite+ formatter configuration, preserving upstream logic, naming, structure and comments.” Use standard root formatter overrides based on upstream conventions; vendored/generated files remain excluded. This approval covers applying the formatter and updating the corresponding Porting Policy rule.

## Shared decisions

- Keep Laravel-compatible PHP methods, signatures, named arguments, configuration, routes, payloads, authorization, and asset-publishing behavior. Any newly proposed public API departure needs the owner's approval under `AGENTS.md`; this plan assumes none.
- Use Horizon as the concrete frontend reference: Options API objects, component-local state and lifecycle hooks, ordinary Vue Router, explicit imports and component registration, readable methods, and Laravel-style method documentation. Retain Telescope's recognizable filenames, screen boundaries, and feature behavior so upstream changes remain easy to map. No Composition API rewrite, TypeScript conversion of the dashboard, generic UI framework, new state-management library, or permanent Vue compatibility mode.
- Churn is not a reason to retain defects, dead imports, obsolete configuration, or misleading comments. Equally, sharing tooling does not require replacing every library or forcing different supported major versions together. Investigate newly found problems and resolve them under the repository's approval rules.
- No extra request-time PHP work, asset compilation on application startup, network calls for frontend dependencies at runtime, or lazy asset downloads. Preserve polling frequency, cancellation, bounded work, and cleanup. Investigate any meaningful bundle or runtime regression before accepting it; benchmark only after confirming the machine is idle with the owner.
- `components` owns framework frontend development. Maintainers commit source, dependency/lockfile changes, and rebuilt distributable assets together. Subtree splitting copies those files unchanged; Composer consumers do not need Node, pnpm, Vite+, or the root catalog.
- Split repositories retain source but are not standalone frontend development environments after Part 2. Developers customizing them use a fork of `components`, or supply explicit versions and tooling in their own split fork. Do not add catalog-expansion code, synthetic split commits, a second lockfile generator, or a publishing build service.
- Keep application skeletons outside the framework dependency workspace. They retain complete manifests and independent builds after `composer create-project`. Do not create catalog references, parent-directory imports, or framework toolchain dependencies in their shipped files. Their config synchronization and installation workflows remain authoritative.
- No generic dependency updater, custom task scheduler, blanket peer-dependency bypass, broad lint suppression, or new mandatory Git hooks. Use native package-manager, Vite+, Vue, and existing framework facilities.

## Verified starting points and references

The paths below are relative to this worktree unless absolute. Read the actual source again before changing it; dependency versions must be checked against current releases and the existing release-age policy when implementation begins.

| Area | Relevant files and constraints |
| --- | --- |
| Horizon reference | `src/horizon/resources/js/app.js`, `base.js`, `components/Alert.vue`, `components/Poll.vue`, and `screens/recentJobs/index.vue`: Vue 3, Router 4, Options API, native Bootstrap 5 APIs. Its style is a reference, not proof that every existing detail is correct. |
| Telescope | `src/telescope/resources/js/`, `resources/views/layout.blade.php`, `resources/sass/`, `vite.config.js`, `vitest.config.js`, `package.json`, `package-lock.json`: Vue 3, Router 4, Bootstrap 5.3 and Vue Test Utils 2; independent npm installation and separate build/test configurations. |
| Upstream | Read-only Laravel references under `/home/binaryfire/workspace/references/laravel/{telescope,horizon}`. Laravel Telescope currently remains on Vue 2. Compare source/tests and the established upstream tracking records; adapting a dashboard to Vue 3 does not change which upstream fixes need porting. |
| Root tooling | `package.json`, `pnpm-workspace.yaml`, `pnpm-lock.yaml`, `vitest.config.ts`, `tsconfig.json`: pnpm already exists; Horizon, Telescope, and Workbench are excluded. The nested exception renderer and AI workbench are not selected by `src/*`. |
| Existing CI | `.github/workflows/tests.yml` runs root MCP/Wayfinder tests and a separate npm installation/test for Telescope. `.github/workflows/skeletons.yml` builds a fresh base application separately. |
| Distribution | `bin/split.sh` splits committed trees, without rewriting manifests. Horizon/Telescope `dist/`, the renderer's `dist/`, Workbench's `public/build/`, and MCP's minified SDK are committed assets. |

Primary research:

- [Vue migration changes](https://v3-migration.vuejs.org/breaking-changes/), [Vue API styles](https://vuejs.org/guide/introduction.html#api-styles), and [Router migration](https://router.vuejs.org/guide/migration/): retain Options API while updating app initialization, router creation, slots, lifecycle hooks, and test render functions.
- [Bootstrap migration](https://getbootstrap.com/docs/5.3/migration/): native component APIs, namespaced data attributes, and changed utilities require corresponding template/Sass updates; changing the dependency alone is insufficient.
- Horizon's actual migration recipes: [Bootstrap/jQuery migration](https://github.com/laravel/horizon/commit/f19486e891310db3eb54ad40520d0af7734a57bd) and [Vue/Router migration](https://github.com/laravel/horizon/commit/267975e4a465625d540974c707a0894400fa15c2). Read their full diffs as discovery, then use current source and supported dependencies rather than copying historical versions.
- [Vite+ monorepos](https://viteplus.dev/guide/monorepo), [migration rules](https://viteplus.dev/guide/migrate-rules), [tests](https://viteplus.dev/guide/test), and [CI](https://viteplus.dev/guide/ci): central quality configuration with package-local build/test configuration, exact toolchain pins, normal workspace task selection, and one setup action.
- [pnpm catalogs](https://pnpm.io/catalogs), [workspace settings](https://pnpm.io/settings), and [lockfile format](https://github.com/pnpm/pnpm/blob/main/pnpm/docs/lockfile.md): catalogs centralize versions, not dependency declarations. Catalog expansion happens during npm packaging, not Git splitting. pnpm 12 may write two YAML documents in one lockfile.
- [Oxc compatibility](https://oxc.rs/compatibility.html): Vue formatting and script linting do not imply Vue template linting. Vue compilation and behavioral tests remain necessary.
- [Dependabot catalogs](https://github.blog/changelog/2025-02-04-dependabot-now-supports-pnpm-workspace-catalogs-ga/), [supported ecosystems](https://docs.github.com/en/code-security/reference/supply-chain-security/supported-ecosystems-and-repositories), and [dependency graph limitations](https://docs.github.com/en/code-security/reference/supply-chain-security/dependency-graph-supported-package-ecosystems): version-update support and security-graph ingestion are separate capabilities. Do not assume a green Dependabot configuration proves transitive advisory coverage.

## Part 1 outcomes Part 2 relies on

The framework response-size correction and Telescope migration are merged. Telescope retains Horizon-style Options API, Router 4, Bootstrap 5.3, its existing endpoints and committed inline assets. Preserve its behavioral tests and literal-tag matching.

- Both dashboards use non-animated modals. Call `hide()` before `dispose()` during unmount, removing owned listeners and timers; preserve nested-modal scrolling behavior.
- Align Horizon with Telescope's supported Bootstrap 5.3 line and package-based Sass import. Preserve the dashboards' own theme selection and light/dark stylesheets.
- Set `$enable-dark-mode: false` before importing Bootstrap, avoiding its unused color-mode rules.

## Part 2 — Vite+, workspace, catalogs, and checks

### 1. Workspace membership and independent skeletons

Use one root pnpm installation/lockfile for these existing development projects:

| Member | Manifest location | Purpose |
| --- | --- | --- |
| Root | `package.json` | Shared checks, task entry points, MCP SDK minification/tests. |
| Horizon | `src/horizon/package.json` | Dashboard build and modal regression tests. |
| Telescope | `src/telescope/package.json` | Migrated dashboard build and tests. |
| Exception renderer | `src/foundation/resources/exceptions/renderer/package.json` | Alpine/Shiki/Tailwind build. |
| Workbench | `src/workbench/package.json` | Authentication-page assets. |
| Wayfinder | `src/wayfinder/package.json` | Generated TypeScript tests and type checking. |
| AI workbench | `dogfood/ai/workbench/package.json` | Maintainer demo frontend. |

Give members explicit, unique private package names using the existing `@hypervel/...` convention so commands are unambiguous. The renderer can be `@hypervel/exception-renderer`, and the demo `@hypervel/ai-workbench`. No package manifest is needed for every PHP component; MCP's existing root-owned commands remain sufficient.

```yaml
packages:
  - 'src/*'
  - 'src/foundation/resources/exceptions/renderer'
  - 'dogfood/ai/workbench'

minimumReleaseAge: 10080

# Versions shown here are structural examples, not upgrade instructions.
catalog:
  vue: ^3.5.43
  vue-router: ^4.4.4
```

Remove the current Horizon/Telescope/Workbench exclusions and their contradictory comments. Leave `skeletons/*` outside workspace installs, catalog migrations, root formatting/linting, and bulk upgrades. The base skeleton remains on its independent Vite setup; this work does not populate the React starter kit or alter its separate future Vite+ setup. Continue validating skeleton installation from a copied/exported directory outside the root workspace so parent dependencies cannot hide omissions.

### 2. Dependencies and install policy

Use the root catalog for shared dependencies and toolchain versions; packages keep explicit declarations of what they import/use. Do not hoist every application dependency into the root or create catalogs for single-package dependencies. Workbench is the only Tailwind 3 consumer, so keep its version directly in its manifest and preserve its configuration. The renderer and AI demo share Tailwind 4 through the default catalog; a shared workspace supports both majors.

Audit direct imports before installing with pnpm's stricter layout. In particular, the exception renderer directly imports `@shikijs/langs/*` and `@shikijs/themes/*` but its manifest currently only declares `shiki`: declare the direct packages and align their versions with Shiki. Likewise declare framework-plugin and Vue Test Utils peers where actually needed. Telescope's physical Sass import is corrected in Part 1; do not add hoisting/symlink workarounds elsewhere.

Select one supported Vite+ release and pin it exactly in the catalog. Use the matching `npm:@voidzero-dev/vite-plus-core@...` alias wherever a direct Vite dependency is required by retained imports/plugins. Follow Vite+'s documented `vite@*`/`vitest@*` override handling and bundled Vitest version; never alias a Vue 2 plugin onto an unsupported runtime to silence errors. Keep each package's required peer provider explicit.

Vite+ `1.3.0` bundles Vitest `5.0.5` and requires Node `^22.18.0 || ^24.11.0 || >=26.0.0`. It is also the minimum fixed version for [GHSA-p458-7748-mcp3](https://github.com/advisories/GHSA-p458-7748-mcp3); do not select an older release. Recheck current releases/advisories before choosing the exact pin. Declare `vite-plus: catalog:` in the root and each member that imports or executes it; one catalog pin supplies them all.

The core alias exposes a Vite+ package version, so compatible consumers can report `unmet peer vite: Installed 1.3.0` even though they run the bundled Vite 8 implementation. Explain this narrow, verified metadata mismatch in a short comment beside the `vite@*`/`vitest@*` overrides in `pnpm-workspace.yaml`, and validate the real plugin compatibility/builds. Do not suppress these warnings with broad peer rules, and do not treat unrelated peer failures as acceptable. The alias does not make an unsupported Vue 2 plugin compatible.

Resolve active dependency advisories, including Wayfinder's `happy-dom`: [GHSA-834v-8h66-3h79](https://github.com/advisories/GHSA-834v-8h66-3h79) requires at least `21.0.4`, outside the existing `^20.14.5` range. Update and test it explicitly. Align Horizon's Bootstrap onto the supported line selected for Telescope; use the existing theme behavior and visual checks to assess its Sass changes, not a compatibility stylesheet.

Fix Horizon's verified modal cleanup alongside that upgrade: its monitoring screen has no unmount cleanup, and Alert retains its modal listener/instance and auto-close timer. Use the same non-animated hide/dispose pattern as Telescope, detach owned listeners and clear the timer, and preserve nested confirmation behavior. Add focused component regression tests using the shared Vitest setup for navigation/unmount while open, repeated open/close, timer/listener cleanup and restoration of body scrolling. Rebuild Horizon's committed `dist/` with the other Part 2 assets.

Fix Telescope's root action failure paths: update root recording state only after a successful toggle response, and use a component-local pending guard to prevent overlapping toggle requests from one dashboard. Clear the guard in `finally`; on failure explain that the recording change could not be confirmed and ask the user to reload. Remove the unused global recording write. Clearing entries reloads only on success and reports failure through the existing alert. Keep endpoints, PHP payloads, confirmation and keyboard behavior. Test the real entry's mounted root instance with a test-only wrapper around Vue's public create/mount APIs: deferred success with a suppressed second request, rejected toggle with guard release, and rejected clear without reload. Do not copy Blade markup or add production testing APIs.

Correct the dashboard lifecycle and request bugs exposed during migration:

- Remove Horizon's obsolete script MIME attributes so its JavaScript is formatted. Key its root RouterView by `$route.path`, preserving query-only updates while remounting resource screens on navigation; remove redundant route watchers. Keep Chart.js instances non-reactive with Vue's `markRaw` so identity-based animation cleanup works, and destroy them on unmount. Correct Alert's accessible label and dead SVG styles. Verify navigation and chart cleanup in the browser against actual templates/assets; keep any canvas harness disposable.
- Make Horizon Poll await an explicit required `callback` prop, skip overlapping ticks, report failures with `console.error`, and release its busy state in `finally`. Retain intervals and visibility behavior. Dashboard waits for all three requests with `Promise.allSettled` before reporting the first failure. Disable immediate polling only on the three list screens that also load on mount. Return request promises through every polling caller.
- In the four Horizon lists, cancel superseded reads and reads owned by an unmounted screen. Skip refreshes while a user load is pending, allow canceled reads to settle without errors, and ignore their late responses. Clear both search debounce timers on unmount. Failed-job search resets pagination and sends one request with Axios query parameters. Use after-index cursors consistently; restore the backend's upstream zero alias and use the calculated inclusive start for both tagged selection and row indices.
- Release failed-job and batch retry state after rejected requests and report that the retry could not be confirmed. Keep the short success cooldown, but let existing polling refresh results instead of issuing delayed requests after navigation. Apply the failure alert to failed-list retries too.
- Monitoring mutations update displayed tags only after server success and report unconfirmed changes on failure. Snapshot submitted tags, deduplicate successful additions, and preserve a newer draft. Horizon's idempotent add does not need pending-state machinery. Disable Telescope's Monitor button until its initial list has loaded. Use a single native database upsert of unique Telescope tags to avoid concurrent duplicate-key failures, retaining strict database errors and the existing API.

Cover failed-job cursor boundaries/search, list supersession and skipped refreshes, Poll overlap and failure recovery, rejected retries, and monitoring failure/draft behavior with focused tests. Extend existing PHP cursor coverage; retain the unique-tag storage test instead of duplicating its conflict assertions. No generic request framework, timer registry, copied Blade test templates or mocked chart-destroy test.

Match Symfony Console's case-insensitive built-in style names in Prompts' visible-text and trailing-tag handling, raising the shared Console requirement to `^8.1.10` across the root and every split manifest that declares it. Verify mixed-case tags against the formatter and preserve uppercase closing tags when replacing the last visible grapheme. Cookie enum-name tests compare creation validity with native Symfony Cookie validation across the supported dependency range instead of assuming that the name `0` is invalid.

Use `defineConfig`/`defineProject` from `vite-plus` for tool configuration and `vite-plus/test` for ordinary test imports. Remove standalone Vitest only when no retained peer/type/module reference requires it. Audit root `tsconfig.json`'s `vitest/globals`: tests already import their APIs, so remove unnecessary globals rather than leaving an unresolved type reference. Do not rewrite arbitrary `vite` API imports or module augmentation names to exports Vite+ does not provide.

Set the root development Node requirement to the selected toolchain's supported range and use Node 24 at or above its required minimum in CI. Pin pnpm once at the root; remove members' npm-specific `devEngines.packageManager` declarations. Use Vite+ for managed runtime/package-manager selection and its documented pnpm `runtimeOnFail: ignore`/`pmOnFail: ignore` settings so pnpm does not compete with it. Preserve the declarations that Vite+ reads. Contributors can instead use exactly the pinned pnpm and a supported Node to bootstrap with `pnpm install --frozen-lockfile` and run the project-local CLI. Explain that plain pnpm no longer auto-switches its version under this policy.

Create the consolidated lockfile with this policy already configured. pnpm 12.10.1 produces a single-document lockfile on a fresh resolution, but an ordinary non-frozen install preserves the current root lockfile's boot document even after manifest changes. Configure the policy, delete the existing root lockfile, and regenerate it with a native install; review the complete version changes and release-age policy. Do not hand-strip YAML or introduce a translator. Verify the resulting lockfile has no boot document and the frozen install leaves it unchanged. No config dependencies are needed for this setup.

Preserve the seven-day release-age policy in the root workspace. Remove root/member npm-only `.npmrc` files after transferring applicable workspace policy; the current root file contains only npm's `min-release-age`. Do not remove independent project files or change skeleton install policy incidentally. Explicitly configure pnpm's dependency lifecycle-script policy: approve only the native tool dependencies whose reviewed installation requires it, and test a clean install. Do not enable every dependency's scripts or assume skipped scripts are harmless. Keep any real registry/auth settings in their supported location and never commit credentials.

Remove npm lockfiles belonging to migrated members only after the root lockfile installs/builds/tests all of them. Keep independent skeleton/package-manager files alone. Expand ignore rules to cover nested member installations, generated test output, and Vite+ caches without hiding distributed assets or source. No shared `vendor/`, `node_modules`, autoloaders, or runtime files between worktrees.

### 3. Root configuration and commands

Use `vite.config.ts` at the root for shared quality settings and the test-project list. Merge Telescope's separate Vitest settings into its package Vite config, and rename Wayfinder's test config appropriately. Remove replaced `vitest.config.*` files rather than keeping conflicting discovery paths.

```ts
import { defineConfig } from 'vite-plus';

export default defineConfig({
    test: {
        projects: [
            'src/wayfinder',
            'src/horizon',
            'src/telescope',
            {
                test: {
                    name: 'mcp',
                    include: ['tests/Mcp/**/*.test.js'],
                    environment: 'node',
                },
            },
        ],
    },
});
```

Retain Wayfinder's `happy-dom`, PHP fixture-generation `globalSetup`, and cached-route test mode. Telescope retains `jsdom` and the Vue plugin; configure the same environment/plugin for Horizon's focused modal tests. Name the dashboard test projects `telescope` and `horizon` so their selectors work. Declare their test dependencies explicitly. Keep include paths relative to the correct project roots. Verify discovery from both root and package directories so root patterns cannot accidentally skip tests.

Use the approved formatting exception: root `lint`/`fmt` settings cover maintained first-party JavaScript, TypeScript, Vue and frontend CSS/config, including repository CJS scripts and the mail theme, with a few upstream-compatible overrides where useful (for example Wayfinder's double quotes). Use Horizon's Options API/naming/organization as the coding reference; copying its incidental whitespace inconsistencies is not necessary to preserve its patterns. Root check scripts generate Wayfinder's imported fixtures before running native `vp check` or `vp check --fix`, without a member-path list, so a fresh installation can type-check the tests. Share config exclusions for dependencies, immutable vendored assets such as Telescope's VarDumper script, generated/minified outputs, test fixtures, Blade templates, archives and independent skeletons. Add format-only exclusions for PHP, Markdown, JSON/YAML metadata and other non-frontend formats found during discovery. Verify discovery includes maintained scripts while preserving fixture contents. Exclusions for formatting do not remove generated files from behavioral/type tests. Maintained ports are included under the approved exception.

Set root formatter options explicitly rather than inheriting Oxfmt's two-space defaults. Use four spaces, semicolons and single quotes for ordinary framework frontend code, with standard `fmt.overrides`: `vueIndentScriptAndStyle: true` for Horizon, `false` for Telescope's upstream layout, and double quotes for MCP. Mirror Wayfinder's upstream `.prettierrc.json` (four spaces, semicolons, double quotes, `trailingComma: 'all'` and print width 80). Choose other wrapping settings against representative upstream files, keeping recognizable layout without per-file exceptions. This preserves upstream comparison while both dashboards share Horizon's component architecture and Options API conventions.

Keep the initial formatting pass in a separate formatting-only commit. Record its full hash in `.git-blame-ignore-revs` with the subsequent configuration/documentation changes so formatting does not obscure history; no dedicated hash-recording commit or custom tooling is needed. Recheck the hash after any final rebase and before merge, updating the entry if it changed. Preserve the commits when merging. Document `git config blame.ignoreRevsFile .git-blame-ignore-revs` in `src/docs/contributions.md` for local blame; GitHub honors the file automatically. Record the approved Porting Policy adaptation in `AGENTS.md` during Part 2.

Use root overrides for browser/Node/test environments and actual product globals (`Telescope`, `Horizon`, etc.). Do not enable broad rules and then suppress entire components. Type-aware checks cover real TypeScript projects; Vue script linting is not Vue template/type verification. Retain Vue compilation and component tests, and Wayfinder's generated-output `tsc` check even if `vp check` also checks authored TypeScript. Never autoformat generated Wayfinder fixtures before asserting what the generator produces.

Disable only `typescript/unbound-method` for test files: mock assertions inspect methods without calling them, and the native rule has no test-aware variant. Express Wayfinder's negative argument-type checks with `expectTypeOf(...).toBeCallableWith(...)` and `@ts-expect-error`, preserving runtime tests. Remove the AI demo's obsolete `baseUrl` and use relative path mappings. Mark MCP's internally handled asynchronous request dispatch explicitly with `void`. Remove Workbench's empty bootstrap module/import and Horizon's unused transition rules, row classes and theme variables; preserve Telescope's live transitions.

Keep build-specific inputs, CSS processing, aliases, output paths, and plugins in each package. Do not extract a shared build-config framework merely because two small configs look alike. Use the current supported Vite configuration surface; audit obsolete options during migration.

Expose ordinary root scripts with these responsibilities:

| Script | Behavior |
| --- | --- |
| `check` / `check:fix` | Generate Wayfinder fixtures, then run native `vp check` / `vp check --fix`, scoped by shared config exclusions. |
| `test` / `test:watch` | All registered projects / explicit watch mode. |
| `test:mcp`, `test:horizon`, `test:telescope`, `test:wayfinder` | Select the named test project. |
| `test:wayfinder:cached` | Run the same Wayfinder project with `WAYFINDER_CACHE_ROUTES=1`. |
| `typecheck:wayfinder` | Generate fixtures, then type-check them with the package's tsconfig. |
| `build` | Build framework/demo asset packages and minify MCP. Explicit workspace filters exclude root itself and skeletons. |
| `build:horizon`, `build:telescope`, `build:exception-renderer`, `build:workbench`, `build:ai-workbench` | Select the matching package build using Vite+'s native task runner. |
| `minify:mcp` | Retain Terser's proven classic-script minification and exact output path. |

For example, a package's build script is `vp build`; a root selector can be `vp run @hypervel/telescope#build`. The existing root script names remain useful compatibility entry points. Do not make the root build recursively invoke itself. `vp test` runs tests; `vp test watch` watches. `vp -C src/telescope build` targets that package with the correct working directory.

Terser remains a small, justified tool: the MCP SDK is loaded as a classic script exposing `window.createMcpApp`, and its tests run both source and minified files. Do not convert it into an ES module or introduce a bundler wrapper merely to claim every operation uses Vite internals. Vite+'s task runner can execute the existing command.

### 4. Distribution and build verification

Preserve the actual consumers, not just successful build exit codes:

| Output | Contract |
| --- | --- |
| Horizon/Telescope `dist/{app.js,app.css,styles.css,styles-dark.css}` | PHP reads and inlines these filenames; no unresolved imports, missing CSS assets, or extra runtime fetches. |
| Renderer `dist/{scripts.js,styles.css}` | `Renderer::js()` emits a classic inline script, not a module. Retain self-contained code, Alpine behavior, Shiki grammars/themes, clipboard, tooltips and error-page styling. |
| Workbench `public/build/` | Preserve the Vite manifest, logo, generated assets and publication to `public/vendor/workbench/build`. Tailwind scanning includes pagination, Testbench and Workbench Blade sources. |
| MCP `resources/js/mcp-sdk.min.js` | Preserve classic-script global/API behavior verified against the source SDK. |
| AI workbench `public/build/` | `dogfood/ai/.gitignore` excludes `workbench/public/`; verify the demo builds without contacting providers, but leave its output untracked and outside committed-asset checks. |

Use package working directories explicitly for all builds. Retain required CSS source scans outside the package directory, and verify generated Tailwind utilities rather than accepting an empty or reduced stylesheet. Rebuild all committed outputs after the dependency/toolchain change and remove obsolete generated chunks that the new build no longer references.

CI may rebuild to verify committed assets but does not publish or commit them. After a frozen installation and deterministic builds, check both tracked differences and new untracked files in the known distributed output directories. `git diff --exit-code` alone misses new chunks. A simple explicit `git status --porcelain -- <output paths>` check is sufficient; no asset ownership registry or hash manifest is needed.

Failure output must name affected files and the relevant root build command, and point to `src/docs/contributions.md`'s compiled-assets instructions. Preserve the external-contributor policy: contributors are not required to commit generated assets; a maintainer regenerates/reviews them before merge. Amend the document's blanket requirement that all CI pass before review: this specific stale-assets check is expected to fail on contributor asset changes until a maintainer rebuilds. Other failures still need investigation; all checks must pass before merge.

### 5. CI, dependency maintenance, and documentation

Consolidate the current JavaScript job in `.github/workflows/tests.yml`. Trust the mounted checkout for Git commands in the container. Use a reviewed commit-SHA-pinned `voidzero-dev/setup-vp` release with its documented inputs, one Node policy, and one frozen root installation. Remove the separate global npm/Telescope installation. Keep Composer installation because Wayfinder generates fixtures through PHP.

Run commands sequentially: the root quality-check script, including its Wayfinder fixture generation; framework asset builds and committed-output verification; all registered JS tests; cached Wayfinder tests; generated-output type check. Generated MCP behavior must be tested after minification. Avoid overlapping processes that generate the same Wayfinder output directory. Pin setup action and toolchain versions; do not introduce remote task-result caching or a bespoke changed-package detector without a demonstrated need. Package-manager caching is sufficient.

Keep skeleton installation CI independent. If quality changes touch shared workflow requirements, verify that its exported application still installs and builds without access to monorepo `node_modules` or catalogs. Do not automatically migrate its npm commands or add skeleton source to root checks.

Add weekly npm-ecosystem Dependabot updates at `/` for the pnpm workspace, retaining the existing GitHub Actions configuration. Set `cooldown.default-days: 7` for normal version updates to match the release-age policy. Group tightly coupled toolchain updates so Vite+, its core alias, and any necessary Vitest pins remain consistent; do not auto-merge them. Verify the updater actually changes aliases/overrides consistently; CI's frozen install and checks remain the acceptance gate. Add the independent base skeleton as its own update directory with `versioning-strategy: increase-if-necessary`, since it has no committed lockfile and fresh installs resolve its ranges. Security fixes require rebuilt assets too, including updates that only change transitive dependencies.

Use native `pnpm audit` during dependency updates and final verification, including development dependencies and all severities. These manifests intentionally place browser-bundled code in development sections. Resolve active workspace advisories before merge, or bring any genuinely blocked finding to the owner. Do not suppress network errors, introduce blanket ignores, or globally disable release-age protection. An urgent verified fix may need a narrowly scoped owner-approved exception. Do not add a wrapper script or duplicate scheduled audit workflow when GitHub's normal dependency graph/alerts cover the consolidated lockfile.

GitHub's documented ingestion limitation concerns the two-document lockfile, not pnpm 12 in general. The standard Vite+ manager policy above avoids that document for this workspace. After merge, verify GitHub recognizes the root lockfile and its transitive dependencies, and verify the removed member-lockfile alerts close. Investigate any actual ingestion failure instead of claiming success from an empty alert list.

Update `src/docs/contributions.md` and `dogfood/ai/README.md` with the final install/check/test/build/watch commands, dependency-update/rebuild procedure, standalone-skeleton boundary, and framework source location. Update changed comments in configs/workflows and remove obsolete independent-npm instructions for workspace members. Preserve end-user application instructions where npm is still correct. At merge, coordinate targeted updates to the Horizon, Telescope, Workbench, MCP and AI entries in `/home/binaryfire/workspace/monorepo/_tmp/upstream-sync/sync.yaml`; replace outdated build commands without changing upstream progress or other work. Do not edit historical plans or instruction files without specific approval.

In the Horizon, Telescope, Wayfinder, MCP and exception-renderer sync guidance, instruct future ports to format a scratch copy of upstream source with the root formatter configuration and matching path overrides before comparing it. Never format the read-only reference checkout. Use the normal formatter command, not a custom sync tool; coordinate these external tracking edits with the owner at merge.

### 6. Verification for PR 2

1. Install from a clean checkout with the pinned Node/pnpm/Vite+ versions and frozen lockfile; verify no manifest/lockfile mutation. Check declared peer relationships and ensure no Vue 2, Vite 5, or stale independent test runner remains in migrated packages. Do not remove legitimate different package majors merely for a uniform dependency tree.
2. Run root quality checks and the full registered JS test set. Preserve original assertions and meaning when upgrading Vitest. Explicitly verify cached Wayfinder generation and `tsc` checks, Telescope's component tests, Horizon's modal regressions, and both MCP SDK forms.
3. Build every framework/demo frontend from the root selectors and at least one directly from its package directory. Verify no accidental dependency on another worktree or untracked install. Exercise clean rebuilds and confirm distributed output checks detect both modifications and new files.
4. Verify Horizon, Telescope, exception pages and Workbench in the browser with their real compiled assets. Check custom base paths, dark/light CSS, JSON/code display, clipboard, dropdowns/modals, and no new network errors. Start the AI demo with faked/intercepted responses; do not invoke paid providers.
5. Run affected PHP rendering/publication tests: Telescope/Horizon asset and nonce tests, Foundation exception-renderer tests, Workbench controller/publication tests, MCP asset-serving tests, and Wayfinder generation tests. Use their actual paths discovered under `tests/`; keep normal repository verification rules.
6. Export representative committed package trees into disposable directories with no root `node_modules`; confirm all required compiled assets and manifests are present and PHP rendering/publication still works. This is a read-only packaging check, not a real split push. Independent skeleton installation/build checks use an exported skeleton outside the workspace.
7. Inspect raw/compressed output sizes and dependency graphs against the baseline. Preserve established request counts and timer cleanup. Investigate meaningful runtime overhead; do not use faster build times to justify a slower application.
8. Validate workflow/config syntax, frozen-install commands, task selection, native audit results, Dependabot policy and the single-document lockfile. Test a deliberately stale generated file and an added untracked chunk to confirm failure messages are useful, then restore those experiments immediately. After merge verify security-graph ingestion and the closure of superseded active and retired archive alerts.
9. Fresh self-review: remove obsolete scripts, lockfiles, npm-only policy, unused dependencies/imports, dead comments, old commands, redundant configs and temporary resources. Confirm no split/release rewrite machinery, API break, unexplained suppression, or hidden test exclusion was introduced. Review the final diff as one coherent design.

Part 2 is complete when every selected framework frontend is maintained through the shared workspace, dependencies and checks are explicit, distributed assets match their source, skeletons remain independent, and the old parallel toolchain setup is gone.
