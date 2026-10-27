# Telescope Vue 3 and shared JavaScript tooling

## Objective and delivery

Modernize Telescope's dashboard using Horizon's actual Vue 3 conventions, then consolidate framework JavaScript development under Vite+, pnpm workspaces, and dependency catalogs. Deliver two independently reviewable PRs against `0.4`, in that order. Do not change `0.3` or `main`.

Work in `/home/binaryfire/workspace/contrib/hypervel/components-vite-plus`. The initial branch is `chore/telescope-vue3`. After PR 1 merges, create PR 2's branch from updated `origin/0.4` in this worktree; do not mix the toolchain migration into PR 1. Commit whole files in focused commits with explanatory bodies. Commit, push, open, or merge PRs only when authorized; never squash by default.

Read this plan, the monorepo root `CLAUDE.md`, and the worktree's `AGENTS.md` after compaction. This plan does not override their approval, verification, source-reading, or dependency-management requirements. No implementation is authorized merely by writing the plan.

Remaining work:

- Implement and verify Part 1, including its generated assets and documentation.
- After Part 1 merges, implement and verify Part 2.

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
| Telescope | `src/telescope/resources/js/`, `resources/views/layout.blade.php`, `resources/sass/`, `vite.config.js`, `vitest.config.js`, `package.json`, `package-lock.json`: Vue 2.7, Router 3, Bootstrap 4, jQuery, Popper 1, Vue Test Utils 1. |
| Upstream | Read-only Laravel references under `/home/binaryfire/workspace/references/laravel/{telescope,horizon}`. Laravel Telescope currently remains on Vue 2. Compare source/tests and the established upstream tracking records; adapting a dashboard to Vue 3 does not change which upstream fixes need porting. |
| Root tooling | `package.json`, `pnpm-workspace.yaml`, `pnpm-lock.yaml`, `vitest.config.ts`, `tsconfig.json`: pnpm already exists; Horizon, Telescope, and Workbench are excluded. The nested exception renderer and AI workbench are not selected by `src/*`. |
| Existing CI | `.github/workflows/tests.yml` runs root MCP/Wayfinder tests and a separate npm installation/test for Telescope. `.github/workflows/skeletons.yml` builds a fresh base application separately. |
| Distribution | `bin/split.sh` splits committed trees, without rewriting manifests. Horizon/Telescope `dist/`, the renderer's `dist/`, Workbench's `public/build/`, and MCP's minified SDK are committed assets. |

Primary research:

- [Vue migration changes](https://v3-migration.vuejs.org/breaking-changes/), [Vue API styles](https://vuejs.org/guide/introduction.html#api-styles), and [Router migration](https://router.vuejs.org/guide/migration/): retain Options API while updating app initialization, router creation, slots, lifecycle hooks, and test render functions.
- [Bootstrap migration](https://getbootstrap.com/docs/5.3/migration/): native component APIs, namespaced data attributes, and changed utilities require corresponding template/Sass updates; changing the dependency alone is insufficient.
- [Vite+ monorepos](https://viteplus.dev/guide/monorepo), [migration rules](https://viteplus.dev/guide/migrate-rules), [tests](https://viteplus.dev/guide/test), and [CI](https://viteplus.dev/guide/ci): central quality configuration with package-local build/test configuration, exact toolchain pins, normal workspace task selection, and one setup action.
- [pnpm catalogs](https://pnpm.io/catalogs), [workspace settings](https://pnpm.io/settings), and [lockfile format](https://github.com/pnpm/pnpm/blob/main/pnpm/docs/lockfile.md): catalogs centralize versions, not dependency declarations. Catalog expansion happens during npm packaging, not Git splitting. pnpm 12 may write two YAML documents in one lockfile.
- [Oxc compatibility](https://oxc.rs/compatibility.html): Vue formatting and script linting do not imply Vue template linting. Vue compilation and behavioral tests remain necessary.
- [Dependabot catalogs](https://github.blog/changelog/2025-02-04-dependabot-now-supports-pnpm-workspace-catalogs-ga/), [supported ecosystems](https://docs.github.com/en/code-security/reference/supply-chain-security/supported-ecosystems-and-repositories), and [dependency graph limitations](https://docs.github.com/en/code-security/reference/supply-chain-security/dependency-graph-supported-package-ecosystems): version-update support and security-graph ingestion are separate capabilities. Do not assume a green Dependabot configuration proves transitive advisory coverage.

## Part 1 — Telescope dashboard migration

### 1. Establish the behavior and build baseline

Read all Telescope frontend components, screens, mixins, Blade layout, styles, and existing query-preview tests. Compare Horizon's corresponding setup and frontend conventions before editing each area. Use current Laravel Telescope as the behavior reference, including fixes already present in Hypervel.

Run the current Telescope tests and production build using its existing independent npm setup. Record expected asset filenames and raw/compressed sizes outside the repository. Exercise the existing UI sufficiently to distinguish migration failures from inherited defects. Source changes and verification for this PR stay in Telescope except for directly required CI/docs updates.

Preserve these contracts:

- `/telescope` and configured empty, root, or nested Telescope paths; route names, IDs, query strings, history navigation, and deep links.
- Existing API endpoints, request bodies, CSRF behavior, timezone formatting, recording toggles, clear confirmation, and auto-load local-storage preference.
- Lists, filtering, family grouping, pagination, pending-job updates, related entries, dumps, monitored tags, SQL formatting/highlighting, JSON inspection, and clipboard contents.
- Light/dark styling, responsive layout, modal/dropdown behavior, and supported HTTP development installations.
- `Telescope::css()`, `js()`, script variables, nonce handling, and the Blade shell. The dashboard assets are inlined; a build that succeeds but emits unresolved external chunks is not acceptable.

### 2. Move onto Vue 3 and the Horizon application pattern

Use package-manager commands to update `src/telescope/package.json` and its npm lockfile. Install supported compatible releases of Vue 3, Router 4, `@vitejs/plugin-vue`, Vue Test Utils 2, and `vue-json-pretty` 2. Replace `@vitejs/plugin-vue2`, Vue 2 compiler dependencies, and the Vue 2 clipboard wrapper. Declare directly imported dependencies; do not rely on npm hoisting to conceal missing declarations.

Upgrade Telescope's standalone Vite/Vitest to supported versions compatible with these plugins and Node 24. PR 1 still uses plain Vite and npm; Vite+ and workspace/catalog changes belong to Part 2. Preserve the minimum-release-age policy and inspect changes in the complete dependency graph. Do not use `--force`, legacy-peer-deps, or a compatibility shim to pass installation.

Follow `src/horizon/resources/js/app.js`:

```js
import { createApp } from 'vue/dist/vue.esm-bundler.js';
import { createRouter, createWebHistory } from 'vue-router';

const app = createApp({
    data() {
        return {
            // Telescope's existing root-owned state.
        };
    },
});

const router = createRouter({
    history: createWebHistory(routerBasePath),
    routes: Routes,
});

app.use(router);
app.component('preview-screen', PreviewScreen);
app.mixin(Base);
app.mount('#telescope');
```

This is a structural example, not replacement code: retain all state, registrations, listeners, and bootstrap settings. The compiler-enabled Vue import is deliberate because the root template lives in Blade. Do not copy Horizon's product-specific globals or proxy-path configuration into Telescope. Check initial navigation and route reuse rather than adding speculative waits or forcing remounts.

Use `defineConfig` and the Vue 3 plugin in Telescope's build and test configs. Configure required Vue production feature flags using the plugin's documented behavior. Retain current output locations and the inline-compatible runtime format. No `@vue/compat`, dual Vue runtime, runtime version detection, or Vue 2 translation layer.

### 3. Convert templates, ownership, and lifecycle behavior

Keep existing screen/file names and route organization. Match Horizon's component import/registration style and readable Options API method layout. Remove redundant `type="text/ecmascript-6"` script attributes, unused imports/components/data, empty option blocks, and empty styles when no longer needed. Keep useful behavior comments and the intentional upstream exclusion notes.

- Replace `slot`/`slot-scope` with `#name`/`v-slot`. Convert named slots on real elements into `<template #name="slotProps">` wrappers while retaining the element's attributes and conditions inside the wrapper. Check slot fallback behavior and table markup; do not accidentally introduce wrapper elements into `<tbody>`.
- Rename lifecycle hooks to Vue 3 hooks, using `beforeUnmount` for resource cleanup. Declare emitted component events. Review array watchers, conditional/list precedence, transitions, `v-for` refs, root attribute forwarding, and router-link behavior against Vue 3's actual semantics.
- Keep data owned by `PreviewScreen` there. It already supplies scoped slots: expose `batch` alongside `entry` to the slots that need related entries, and consume both through `slotProps`. Remove `$parent.entry`/`$parent.batch` writes and now-unused mirrored screen state. Do not replace this with a store or a generic event bus. Preserve the `ready` event used by query highlighting and its timing after the rendered content exists.

```vue
<!-- PreviewScreen.vue -->
<slot v-if="ready && entry" name="after-attributes-card" :entry="entry" :batch="batch" />

<!-- A detail screen -->
<template #after-attributes-card="slotProps">
    <!-- Existing detail markup. -->
    <related-entries :entry="slotProps.entry" :batch="slotProps.batch" />
</template>
```

- Preserve the request-controller ownership and stale-response guards in `IndexScreen`, `PreviewScreen`, dumps, and monitoring. Abort on route changes/unmount, clear their timers, and prevent canceled responses from starting new polling. Keep existing 2.5-second list/job polling, 2-second dump polling, and minute-based relative-time refresh; no interval-per-row design or deep watcher over full payloads.
- Keep search debouncing local to its owner, cancel pending work when that owner unmounts, and do not swallow unexpected router/navigation errors. Retain the existing retry distinction between canceled requests, network errors, and HTTP errors.
- Avoid maintaining duplicate payload copies solely for reactivity. Check the dump renderer's ref array and initialization tracking as entries are replaced, and release no-longer-needed component resources. New guards need a demonstrated reachable failure, not hypothetical resilience.
- Fix the verified mail-preview omission while converting that template: Reply-To, CC and BCC conditions currently read `slotProps.entry.replyTo`/`cc`/`bcc`, but `MailWatcher::recordMail()` records these under `entry.content`. Test populated and empty address collections and render only populated rows; preserve the stored payload and download/preview endpoints.

### 4. Complete the UI dependency migration

Adopt Bootstrap 5's native Modal/Dropdown APIs as Horizon does, removing Telescope's unsupported Bootstrap 4/jQuery/Popper 1 combination. This is an explicit part of the dashboard refactor: it removes obsolete integration code and leaves the two dashboards using the same native component patterns. It is not a claim that Vue 3 itself requires Bootstrap 5.

- Read Horizon's modal integration and the Bootstrap migration guide; use direct imports, component-owned instances and cleanup. Do not copy Horizon code without checking event-listener/timer disposal.
- Update modal/dropdown data attributes, spacing/direction utilities, badges, and changed Sass variables throughout Telescope's Vue files and Blade layout. Preserve the intended appearance, including dark-theme colors and nested confirmation dialogs. Adapt custom selectors to the new markup instead of adding a Bootstrap 4 compatibility stylesheet.
- Replace jQuery DOM access in the minute-based time display and focus handling with ordinary DOM operations or Vue refs. Write time labels as text. Remove the global Popper assignment and unused jQuery/Popper dependencies; explicitly declare Bootstrap's actual peers where needed.
- Replace `vue-copy-to-clipboard` with a direct use of its existing underlying `copy-to-clipboard` library inside Telescope's `CopyClipboard.vue`. The wrapper uses Vue 2 functional/render APIs despite its broad peer range. Direct use avoids another Vue adapter and preserves HTTP clipboard behavior. Do not replace it with secure-context-only `navigator.clipboard.writeText` or invent a clipboard framework.
- Preserve string and tab-indented JSON copying, prevent anchor navigation, report copy success only on success, and clear feedback timers on unmount. Keep the component's `data` prop and default slot. Use the existing alert surface for a real failure rather than silently displaying success.
- Verify `vue-json-pretty`'s updated CSS, primitive/object/array rendering, expansion and large-payload behavior. Avoid remounting the entire dashboard or serializing payloads repeatedly to bridge the version change.

### 5. Verification for PR 1

Use Vue Test Utils 2 with real Vue 3 mounting where behavior matters. Port the existing `resources/js/screens/queries/preview.test.js` assertions without weakening them: use imported `h`, function-valued `$slots`, and `global.mocks`/`global.stubs`. Retain SQL dialect, PostgreSQL operator, copy-content, and highlighting coverage. Remove fixtures' Vue 2 render APIs.

Add focused tests beside the relevant components rather than a test for every mechanical syntax edit:

| Test area | Required behavior |
| --- | --- |
| Application/router | Correct base path, direct preview route, initial load, back/forward, query changes and reused route IDs; no duplicate startup requests/listeners. |
| Index/polling | Filtering, family grouping, pagination, auto-load on/off, canceled in-flight responses, unmount cleanup, and network retry versus HTTP failure. Fake timers and deferred promises avoid slow sleeps. |
| Preview | Slot data including related entries, clearing stale data on ID changes/failure, pending-job updates, and `ready` after usable content. |
| Clipboard | Strings/objects, success/failure, HTTP-capable implementation, no accidental navigation and no timer after unmount. |
| Modal/monitoring | Confirmation/cancel behavior, nested modal state, focus, repeated open/close, tag removal, listener/instance cleanup. |
| Dumps/JSON | Repeated entries/ref updates, dump expansion, JSON primitives/arrays/objects, and no unwanted repeated initialization. |
| Mail preview | Recorded Reply-To/CC/BCC addresses appear; empty collections produce no empty address rows. |

Run each changed test file immediately, then the complete Telescope JS tests and production build. Keep `.github/workflows/tests.yml` running the package's own `npm test`; update requirements only as needed for the supported Node/npm toolchain. Add a build check for this dashboard in that job.

Perform browser verification using a disposable Testbench application and the real rendered Telescope Blade shell/compiled assets. Use SQLite and fixture entries or intercepted API responses; no external services or real user data. Use browser automation available in the implementation environment, with bounded startup/readiness/shutdown and unconditional cleanup. Do not introduce a general browser-testing platform just for this migration. Exercise all navigation sections and the table above, light/dark themes, nested paths, modal/dropdown interactions, copy feedback, and developer-console errors. PHP rendering or a successful JS build alone does not verify a Vue migration.

Run affected PHP tests, especially `tests/Telescope/Http/{RouteTest,CspNonceTest,AuthorizationTest}.php`, package metadata, asset rendering, and install behavior. Follow `AGENTS.md`'s formatting/static-analysis/test order for any PHP changes; use `composer test:parallel -- --processes=4 tests/Telescope` for the package suite. Do not run a full framework suite merely for JS formatting.

Rebuild and commit Telescope's complete `dist/` alongside the source. Check light/dark CSS and `app.css`/`app.js` filenames, absence of new unresolved imports or runtime asset requests, correct PHP rendering/nonces, and raw/compressed output changes. A meaningful increase needs explanation and optimization; no arbitrary percentage budget or benchmark claim without measurement.

Document the Vue 3/Horizon reference, slot/ownership changes, and how to translate upstream Vue 2 fixes in the existing frontend contributor documentation. This is maintainer guidance, not a new end-user PHP API. Keep the last-synced upstream tracking accurate without pretending the migration imported unreviewed upstream changes.

PR 1 is complete only when the migrated dashboard works independently with its own npm manifest/lockfile, all affected checks pass, compiled assets are committed, and obsolete Vue 2/UI integration code is removed.

## Part 2 — Vite+, workspace, catalogs, and checks

### 1. Workspace membership and independent skeletons

Use one root pnpm installation/lockfile for these existing development projects:

| Member | Manifest location | Purpose |
| --- | --- | --- |
| Root | `package.json` | Shared checks, task entry points, MCP SDK minification/tests. |
| Horizon | `src/horizon/package.json` | Dashboard build. |
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
  - '!skeletons/**'

minimumReleaseAge: 10080

# Versions shown here are structural examples, not upgrade instructions.
catalog:
  vue: ^3.5.43
  vue-router: ^4.4.4

# Different supported CSS generations need not be forcibly unified.
catalogs:
  tailwind3:
    tailwindcss: ^3.4.19
```

Remove the current Horizon/Telescope/Workbench exclusions and their contradictory comments. Leave `skeletons/*` outside workspace installs, catalog migrations, root formatting/linting, and bulk upgrades. The base skeleton remains on its independent Vite setup; this work does not populate the React starter kit or alter its separate future Vite+ setup. Continue validating skeleton installation from a copied/exported directory outside the root workspace so parent dependencies cannot hide omissions.

### 2. Dependencies and install policy

Use the root catalog for shared dependencies and toolchain versions; packages keep explicit declarations of what they import/use. Do not hoist every application dependency into the root. Use named catalogs only for real incompatible major requirements, not a separate catalog for every package. Preserve Workbench's Tailwind 3 configuration unless a separately established requirement calls for migrating that application; a shared workspace supports it alongside Tailwind 4.

Audit direct imports before installing with pnpm's stricter layout. In particular, the exception renderer directly imports `@shikijs/langs/*` and `@shikijs/themes/*` but its manifest currently only declares `shiki`: declare the direct packages and align their versions with Shiki. Likewise declare framework-plugin and Vue Test Utils peers where actually needed. Fix Sass imports that assume a physical `node_modules` location using supported package resolution, not hoisting/symlink workarounds.

Select one supported Vite+ release and pin it exactly in the catalog. Use the matching `npm:@voidzero-dev/vite-plus-core@...` alias wherever a direct Vite dependency is required by retained imports/plugins. Follow Vite+'s documented `vite@*`/`vitest@*` override handling and bundled Vitest version; never alias a Vue 2 plugin onto an unsupported runtime to silence errors. Keep each package's required peer provider explicit.

The registry-verified compatible starting candidate is Vite+ `1.3.0`, bundling Vitest `5.0.5` and requiring Node `^22.18.0 || ^24.11.0 || >=26.0.0`. Recheck releases and advisories at implementation rather than blindly copying this candidate. Declare `vite-plus: catalog:` in the root and each member that imports or executes it; one catalog pin supplies them all.

Use `defineConfig`/`defineProject` from `vite-plus` for tool configuration and `vite-plus/test` for ordinary test imports. Remove standalone Vitest only when no retained peer/type/module reference requires it. Audit root `tsconfig.json`'s `vitest/globals`: tests already import their APIs, so remove unnecessary globals rather than leaving an unresolved type reference. Do not rewrite arbitrary `vite` API imports or module augmentation names to exports Vite+ does not provide.

Set the root development Node requirement to the selected toolchain's supported range and use Node 24 at or above its required minimum in CI. Pin pnpm once at the root; remove members' npm-specific `devEngines.packageManager` declarations. Use Vite+ for managed runtime/package-manager selection and its documented pnpm `runtimeOnFail: ignore`/`pmOnFail: ignore` settings so pnpm does not compete with it. Preserve the actual Node/pnpm declarations that Vite+ reads. Contributors with those versions already installed can bootstrap with `pnpm install --frozen-lockfile` and run the project-local CLI through scripts or `pnpm exec vp`; a global CLI is not required to execute project checks.

Preserve the seven-day release-age policy in the root workspace. Remove obsolete member `.npmrc` settings only after transferring applicable policy. Explicitly configure pnpm's dependency lifecycle-script policy: approve only the native tool dependencies whose reviewed installation requires it, and test a clean install. Do not enable every dependency's scripts or assume skipped scripts are harmless. Keep registry/auth settings in their supported location and never commit credentials.

Remove npm lockfiles belonging to migrated members only after the root lockfile installs/builds/tests all of them. Keep independent skeleton/package-manager files alone. Expand ignore rules to cover nested member installations, generated test output, and Vite+ caches without hiding distributed assets or source. No shared `vendor/`, `node_modules`, autoloaders, or runtime files between worktrees.

### 3. Root configuration and commands

Use `vite.config.ts` at the root for shared quality settings and the test-project list. Merge Telescope's separate Vitest settings into its package Vite config, and rename Wayfinder's test config appropriately. Remove replaced `vitest.config.*` files rather than keeping conflicting discovery paths.

```ts
import { defineConfig } from 'vite-plus';

export default defineConfig({
    test: {
        projects: [
            'src/wayfinder',
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

Retain Wayfinder's `happy-dom`, PHP fixture-generation `globalSetup`, and cached-route test mode; Telescope retains `jsdom` and the Vue plugin. Keep package test include paths relative to their correct project roots. Verify discovery from both root and package directories so root patterns cannot accidentally skip tests.

Root `lint`/`fmt` settings apply consistently to authored JavaScript, TypeScript, Vue and relevant frontend CSS/config. Match Horizon's prevailing readable style, normalized by one formatter: four-space indentation, single quotes and semicolons; use Vue script/style indentation where appropriate. Restrict file selection to owned frontend/test/config paths. Exclude dependencies, bundled/copied third-party assets such as Telescope's VarDumper script, generated/minified outputs, Blade/PHP, instruction files, historical plans, and independent skeletons. Exclusions for formatting do not remove generated files from their behavioral/type tests.

Use root overrides for browser/Node/test environments and actual product globals (`Telescope`, `Horizon`, etc.). Do not enable broad rules and then suppress entire components. Type-aware checks cover real TypeScript projects; Vue script linting is not Vue template/type verification. Retain Vue compilation and component tests, and Wayfinder's generated-output `tsc` check even if `vp check` also checks authored TypeScript. Never autoformat generated Wayfinder fixtures before asserting what the generator produces.

Keep build-specific inputs, CSS processing, aliases, output paths, and plugins in each package. Do not extract a shared build-config framework merely because two small configs look alike. Use the current supported Vite configuration surface; audit obsolete options during migration.

Expose ordinary root scripts with these responsibilities:

| Script | Behavior |
| --- | --- |
| `check` / `check:fix` | Vite+ quality checks / their supported fixes on the selected source paths. |
| `test` / `test:watch` | All registered projects / explicit watch mode. |
| `test:mcp`, `test:telescope`, `test:wayfinder` | Select the existing named test project. |
| `test:wayfinder:cached` | Run the same Wayfinder project with `WAYFINDER_CACHE_ROUTES=1`. |
| `typecheck:wayfinder` | Generate fixtures, then type-check them with the package's tsconfig. |
| `build` | Build framework/demo asset packages and minify MCP. Explicit workspace filters exclude root itself and skeletons. |
| `build:horizon`, `build:telescope`, `build:exceptions`, `build:workbench`, `build:ai` | Select the appropriate package build using Vite+'s native task runner. |
| `minify:mcp` | Retain Terser's proven classic-script minification and exact output path. |
| `audit` | Run pnpm's advisory check across the locked workspace, including development dependencies. |

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

Failure output must name affected files and the relevant root build command, and point to `src/docs/contributions.md`'s compiled-assets instructions. Preserve the external-contributor policy: contributors are not required to commit generated assets; a maintainer regenerates/reviews them before merge. A stale-assets failure explains that maintainer action rather than changing that policy silently.

### 5. CI, dependency maintenance, and documentation

Consolidate the current JavaScript job in `.github/workflows/tests.yml`. Use a reviewed commit-SHA-pinned `voidzero-dev/setup-vp` release with its documented inputs, one Node policy, and one frozen root installation. Remove the separate global npm/Telescope installation. Keep Composer installation because Wayfinder generates fixtures through PHP.

Run commands sequentially: generate required Wayfinder fixtures; quality checks; framework asset builds and committed-output verification; all registered JS tests; cached Wayfinder tests; generated-output type check. Generated MCP behavior must be tested after minification. Avoid overlapping processes that generate the same Wayfinder output directory. Pin setup action and toolchain versions; do not introduce remote task-result caching or a bespoke changed-package detector without a demonstrated need. Package-manager caching is sufficient.

Keep skeleton installation CI independent. If quality changes touch shared workflow requirements, verify that its exported application still installs and builds without access to monorepo `node_modules` or catalogs. Do not automatically migrate its npm commands or add skeleton source to root checks.

Add weekly npm-ecosystem Dependabot updates at `/` for the pnpm workspace, retaining the existing GitHub Actions configuration. Group tightly coupled toolchain updates so Vite+, its core alias, and any necessary Vitest pins remain consistent; do not auto-merge them. Verify the updater actually changes aliases/overrides consistently; CI's frozen install and checks remain the acceptance gate. Add the existing independent base skeleton as its own update directory. Security fixes require rebuilt assets too, including updates that only change transitive dependencies.

Add `.github/workflows/javascript-dependencies.yml` with one native `pnpm audit --audit-level=high` job for the complete workspace, not `--prod`: these manifests intentionally put browser-bundled dependencies in development sections. Trigger it for root/member manifest, catalog and lockfile changes, on a weekly schedule, and manually so newly disclosed advisories surface without a code change. It needs the JavaScript toolchain, not Composer or PHP services. Report advisory failures with package paths; retain visibility of lower-severity findings for review. Do not suppress network errors, add blanket advisory ignores, or disable release-age protection globally to accept a new fix. A narrowly scoped release-age exception for an urgent verified fix requires an explicit maintenance decision.

GitHub currently documents missing dependency-graph ingestion for pnpm 12's two-document lockfile; the repository already has that format. Keep native pnpm auditing as an independent source of alerts. Verify Dependabot's actual pnpm/catalog update behavior and security settings rather than promising complete GitHub transitive alerts. Do not strip lockfile sections or build a custom lockfile translator to satisfy a scanner.

Update `src/docs/contributions.md` and `dogfood/ai/README.md` with the final install/check/test/build/watch commands, dependency-update/rebuild procedure, standalone-skeleton boundary, and framework source location. Update changed comments in configs/workflows and remove obsolete independent-npm instructions for workspace members. Preserve end-user application instructions where npm is still correct. Do not edit historical plans or instruction files without specific approval.

### 6. Verification for PR 2

1. Install from a clean checkout with the pinned Node/pnpm/Vite+ versions and frozen lockfile; verify no manifest/lockfile mutation. Check declared peer relationships and ensure no Vue 2, Vite 5, or stale independent test runner remains in migrated packages. Do not remove legitimate different package majors merely for a uniform dependency tree.
2. Run root quality checks and the full registered JS test set. Preserve original assertions and meaning when upgrading Vitest. Explicitly verify cached Wayfinder generation and `tsc` checks, Telescope's component tests, and both MCP SDK forms.
3. Build every framework/demo frontend from the root selectors and at least one directly from its package directory. Verify no accidental dependency on another worktree or untracked install. Exercise clean rebuilds and confirm distributed output checks detect both modifications and new files.
4. Verify Horizon, Telescope, exception pages and Workbench in the browser with their real compiled assets. Check custom base paths, dark/light CSS, JSON/code display, clipboard, dropdowns/modals, and no new network errors. Start the AI demo with faked/intercepted responses; do not invoke paid providers.
5. Run affected PHP rendering/publication tests: Telescope/Horizon asset and nonce tests, Foundation exception-renderer tests, Workbench controller/publication tests, MCP asset-serving tests, and Wayfinder generation tests. Use their actual paths discovered under `tests/`; keep normal repository verification rules.
6. Export representative committed package trees into disposable directories with no root `node_modules`; confirm all required compiled assets and manifests are present and PHP rendering/publication still works. This is a read-only packaging check, not a real split push. Independent skeleton installation/build checks use an exported skeleton outside the workspace.
7. Inspect raw/compressed output sizes and dependency graphs against the baseline. Preserve established request counts and timer cleanup. Investigate meaningful runtime overhead; do not use faster build times to justify a slower application.
8. Validate the changed workflow/config syntax, frozen-install commands, task selection and advisory job. Test a deliberately stale generated file and an added untracked chunk to confirm failure messages are useful, then restore those experiments immediately.
9. Fresh self-review: remove obsolete scripts, lockfiles, npm-only policy, unused dependencies/imports, dead comments, old commands, redundant configs and temporary resources. Confirm no split/release rewrite machinery, API break, unexplained suppression, or hidden test exclusion was introduced. Review the final diff as one coherent design.

Part 2 is complete when every selected framework frontend is maintained through the shared workspace, dependencies and checks are explicit, distributed assets match their source, skeletons remain independent, and the old parallel toolchain setup is gone.
