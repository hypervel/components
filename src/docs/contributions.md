# Contribution Guide

- [Accepted Contributions](#accepted-contributions)
- [Bug Reports](#bug-reports)
- [Support Questions](#support-questions)
- [Missing Upstream Functionality](#missing-upstream-functionality)
- [Pull Request Requirements](#pull-request-requirements)
- [Automated Reviews](#automated-reviews)
- [Which Branch?](#which-branch)
- [Quality Checks](#quality-checks)
- [Compiled Assets](#compiled-assets)
- [AI-Generated Contributions](#ai-generated-contributions)
- [Security Vulnerabilities](#security-vulnerabilities)
- [Coding Style](#coding-style)
    - [PHPDoc](#phpdoc)
        - [Generating Facade Docblocks](#generating-facade-docblocks)
- [Code of Conduct](#code-of-conduct)

<a name="accepted-contributions"></a>
## Accepted Contributions

Hypervel closely tracks Laravel's public APIs and behavior. To keep the project maintainable, contributions must follow these rules:

<div class="content-list" markdown="1">

- **Upstream features and API changes:** Except for bug fixes and performance improvements, new features or changes to APIs and behavior inherited from Laravel or another upstream package must come from that upstream project. Submit a pull request to the owning project first. If accepted upstream, the Hypervel team will assess it for inclusion through the upstream sync process. The team handles [ports and upstream synchronization](#missing-upstream-functionality); do not submit pull requests for this work.
- **Bug fixes and performance improvements:** These can be submitted directly to Hypervel, even when the same issue exists in Laravel or another upstream package. You do not need to wait for upstream to accept or fix it.
- **New Hypervel-specific features:** These must first be proposed in [GitHub Discussions](https://github.com/hypervel/components/discussions). Wait for a maintainer to approve the proposal before opening a pull request.

</div>

Documentation corrections can also be submitted directly.

Pull requests must address one clearly defined problem or change. Include all source changes, tests, and documentation needed to complete the contribution.

Bug-fix code pull requests must explain the root cause and include a regression test that fails without the fix and passes with it.

Performance improvements must include reproducible before-and-after benchmarks, the commands and environment used, and an explanation of any tradeoffs. Tests must verify that supported behavior is preserved.

Submissions that do not meet these requirements will be closed without a detailed review. Meeting the requirements does not guarantee acceptance or a review timetable.

If you are unsure whether a change fits Hypervel's direction, start a [GitHub Discussion](https://github.com/hypervel/components/discussions) before opening a pull request.

<a name="bug-reports"></a>
## Bug Reports

Hypervel encourages pull requests that fix bugs, not just bug reports. Complete bug-fix pull requests take priority over issue-only reports. You do not need to provide a fix to report a bug.

Bug reports must have a clear title and description and include:

<div class="content-list" markdown="1">

- The affected Hypervel package and exact version or commit.
- The PHP and Swoole versions, plus relevant database or external-service versions. If Swoole is not installed, state that explicitly.
- A minimal reproduction, provided as a failing test, a self-contained code example, or a public reproduction repository.
- The exact steps and commands needed to reproduce the failure.
- The expected behavior, actual behavior, and relevant error output.

</div>

Reproduce the issue yourself before submitting it. Describe a demonstrated failure in supported usage; speculation about what might fail is not sufficient. Report suspected security vulnerabilities [privately](#security-vulnerabilities), not in public issues.

Search existing issues and discussions before opening a report. If the same issue has already been reported, add new reproduction details there.

Reports that lack the required information will be closed until a complete reproduction is provided.

If a maintainer requests additional information on an existing issue and applies the `not enough info` label, respond within 14 days. Issues without a reporter response will be closed with an explanation and can be reconsidered when the information is supplied. A response stops automatic closure while the maintainer assesses it. Issues are not closed merely because they are inactive.

Maintainers must describe the information needed before applying the label. Remove and reapply it when requesting further information after a response. The `maintainer-directed` label exempts an issue from automatic closure.

If you notice improper DocBlock, PHPStan, or IDE warnings while using Hypervel, do not create a GitHub issue. Instead, please submit a pull request to fix the problem.

The Hypervel source code is managed on GitHub in the [hypervel/components](https://github.com/hypervel/components) repository.

<a name="support-questions"></a>
## Support Questions

Use GitHub issues for [bug reports](#bug-reports) and [potentially missed upstream functionality](#missing-upstream-functionality). Use [GitHub Discussions](https://github.com/hypervel/components/discussions) for support questions, ideas, and Hypervel-specific feature proposals. Before proposing a feature, read the [accepted contribution rules](#accepted-contributions).

<a name="porting-laravel-functionality"></a>
<a name="missing-upstream-functionality"></a>
## Missing Upstream Functionality

The Hypervel team handles ports and upstream synchronization for Laravel and third-party packages, including Saloon and Spatie's Data and Permission packages. Do not submit pull requests to port upstream functionality or synchronize upstream changes. This restriction does not prevent direct contributions that fix bugs or improve performance, including defects shared with upstream.

If you believe Hypervel has missed existing upstream functionality, first check the affected package's README and documentation for its upstream reference and intentional differences, and the [upstream sync tracker](https://github.com/hypervel/components/blob/0.4/docs/upstream-sync/sync.yaml). For Laravel functionality, also check the [Laravel porting guide](/docs/{{version}}/porting-from-laravel). The tracker records the upstream branch and commit reviewed through for each package. Recent upstream changes may be awaiting the next sync. Intentionally omitted deprecated or legacy APIs, architectural incompatibilities, and features intentionally excluded from Hypervel's direction are not missing functionality.

If the upstream package has no tracker entry or its `checked_through` value is null, ask in [GitHub Discussions](https://github.com/hypervel/components/discussions/categories/q-a) before opening a missing-functionality issue. An absent record is not evidence that a feature was missed.

If the functionality was already present at the recorded commit and is not an intentional omission, use the [missing upstream functionality form](https://github.com/hypervel/components/issues/new?template=missing-upstream-functionality.yml). Include the affected Hypervel package and version, links to the upstream implementation and relevant documentation, the recorded branch and `checked_through` commit, and a minimal example demonstrating the missing behavior. Search existing issues and [tracked deferred work](https://github.com/hypervel/components/blob/0.4/docs/todo.md) first; a reviewed commit does not mean every upstream change has been ported. The team will assess whether the functionality was missed and handle any required port.

<a name="pull-request-requirements"></a>
## Pull Request Requirements

Every pull request must:

<div class="content-list" markdown="1">

- Explain the problem, why the change is needed, and the resulting behavior or documentation correction.
- Identify which [accepted contribution category](#accepted-contributions) it belongs to. Documentation-only corrections must identify the incorrect or missing guidance.
- Link the approved discussion when adding a Hypervel-specific feature.
- Include tests for new or changed behavior, including relevant failure cases and coroutine isolation.
- Update the documentation when public APIs, configuration, or documented behavior change.
- Describe the verification performed and its results.
- Pass the required automated checks before human review is requested.

</div>

Keep unrelated changes in separate pull requests. Do not submit placeholders, unfinished implementations, or changes that depend on the maintainer completing the work. Complete the [required local checks](#quality-checks) before opening a code pull request.

Mark the pull request ready for review only when it meets these requirements and you have addressed the [automated review findings](#automated-reviews). Draft pull requests are not reviewed. External draft pull requests with no contributor activity for seven days will be closed with an explanation. Pushes, edits, and author replies count as activity; bot comments do not. The timer starts when a pull request becomes a draft or is reopened, and existing drafts receive seven days from their first cleanup notice.

Maintainer-authored and bot-authored pull requests are exempt from this draft cleanup. Maintainers must apply the `maintainer-directed` label to exempt other work they are directing. Ready-for-review pull requests are not closed for inactivity or failing CI.

<a name="automated-reviews"></a>
## Automated Reviews

Hypervel uses automated review tools to identify potential correctness, maintainability, and style issues before maintainer review.

Review bots can report false positives or recommend unnecessary changes. You must investigate every finding. Fix valid issues and reply with an explanation of the correction. For findings that do not apply, reply with evidence explaining why. Do not blindly apply suggestions or dismiss findings without investigation.

Before requesting maintainer review, resolve each finding with a correction or an evidence-backed reply. If a finding remains disputed, explicitly flag the evidence and the decision needed from a maintainer instead. Marking a thread resolved does not replace the required investigation, correction, or reply.

<a name="which-branch"></a>
## Which Branch?

Bug fixes and backward-compatible improvements for the current release must be sent to the current version branch (currently `0.4`).

Breaking changes or work intended for the next minor release must be sent to the `main` branch. Hypervel is pre-1.0, so minor releases may include breaking changes, but those changes must be intentional and documented. Choosing a target branch does not replace the [contribution requirements](#accepted-contributions) or approval for new Hypervel-specific features.

<a name="quality-checks"></a>
## Quality Checks

Before opening a code pull request, run `composer fix` from the repository root and ensure it completes successfully. Review any formatting changes it produces. Repeat this verification after subsequent code changes, before requesting review.

```shell
composer fix
```

This command runs php-cs-fixer, both PHPStan configurations, the parallel framework test suite, the Testbench package-mode suite, and the Testbench consumer-package tests.

All CI checks must pass before a pull request will be reviewed. Investigate and address failures before requesting maintainer review.

Documentation-only changes do not require the PHP test and analysis suite. Verify the changed instructions, examples, and links, and ensure the applicable CI checks pass.

During development, you can run individual checks to investigate failures. These do not replace the required `composer fix` run:

```shell
composer test:parallel
composer analyse
composer lint
composer lint:fix
```

If your change affects Wayfinder's generated TypeScript, also run:

```shell
pnpm test:wayfinder
pnpm test:wayfinder:cached
pnpm typecheck:wayfinder
```

To run the database integration tests, pass the connection name to the database test runner:

```shell
bin/run-database-tests.sh mysql
bin/run-database-tests.sh mariadb
bin/run-database-tests.sh pgsql
bin/run-database-tests.sh sqlite
```

The runner sets `DB_CONNECTION` from this argument. Configure the remaining database environment variables (see `.env.example`) before running the command. Any additional ParaTest options are forwarded to each database test suite. For example, you may use `bin/run-database-tests.sh pgsql -p 3 --filter=EloquentPrunableTest` to control the worker count and select a specific test.

Do not disable checks, skip affected tests, or weaken assertions to make a pull request pass.

<a name="compiled-assets"></a>
## Compiled Assets

Hypervel packages such as Horizon and Telescope include JavaScript and CSS assets for their dashboards. If you are submitting a change that affects dashboard source files, do not commit the generated `dist` files unless a maintainer asks you to do so. Due to their size, compiled assets cannot realistically be reviewed with the same care as source files. This could be exploited as a way to inject malicious code into Hypervel. In order to defensively prevent this, compiled assets will be generated and committed by Hypervel maintainers when needed.

<a name="ai-generated-contributions"></a>
## AI-Generated Contributions

You are responsible for every part of your submission, including code generated by an agent. Before submitting code, review the complete diff and run the [required checks](#quality-checks). Verify every claim in any issue or pull request you submit.

You must understand the implementation and be able to explain its behavior, tests, and tradeoffs during review. Do not submit generated findings you have not reproduced or generated changes you have not verified. You must investigate and respond to [automated review findings](#automated-reviews) yourself, including false positives.

Bulk automated issue creation or pull request submission is prohibited. Accounts engaging in this behavior will be blocked from the project.

<a name="security-vulnerabilities"></a>
## Security Vulnerabilities

If you discover a security vulnerability within Hypervel, please send an email to Albert Chen at <a href="mailto:albert@hypervel.org">albert@hypervel.org</a>. All security vulnerabilities will be promptly addressed.

<a name="coding-style"></a>
## Coding Style

Hypervel follows the [PSR-4](https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-4-autoloader.md) autoloading standard and uses php-cs-fixer to enforce coding style. All PHP files must declare strict types. Use modern PHP 8.4+ features where appropriate.

Parameters, return values, properties, class constants, and closure signatures must be fully typed wherever PHP and inherited signatures permit.

<a name="phpdoc"></a>
### PHPDoc

Below is an example of a valid Hypervel documentation block:

```php
/**
 * Register a binding with the container.
 *
 * @throws \Exception
 */
public function bind(string|array $abstract, Closure|string|null $concrete = null, bool $shared = false): void
{
    // ...
}
```

When the `@param` or `@return` attributes are redundant due to the use of native types, they can be removed:

```php
/**
 * Execute the job.
 * [tl! remove]
 * @return void [tl! remove]
 */
public function handle(AudioProcessor $processor): void
{
    // ...
}
```

Use `@param` and `@return` annotations to describe types that native declarations cannot express, such as array element types:

```php
/**
 * Get the attachments for the message.
 * [tl! add]
 * @return array<int, \Hypervel\Mail\Mailables\Attachment> [tl! add]
 */
public function attachments(): array
{
    return [
        Attachment::fromStorage('/path/to/file'),
    ];
}
```

<a name="generating-facade-docblocks"></a>
#### Generating Facade Docblocks

When a change affects a service exposed through a facade, regenerate that facade's docblock from the repository root:

```shell
composer facade -- Hypervel\\Support\\Facades\\Cache
```

You may use the `--lint` option to check the generated docblock without changing the file:

```shell
composer facade -- --lint Hypervel\\Support\\Facades\\Cache
```

For details about configuring and using the generator in a package, see the [package development documentation](/docs/{{version}}/packages#generating-facade-docblocks).

<a name="code-of-conduct"></a>
## Code of Conduct

The Hypervel code of conduct is derived from the Ruby code of conduct. Any violations of the code of conduct may be reported to Albert Chen (albert@hypervel.org):

<div class="content-list" markdown="1">

- Participants will be tolerant of opposing views.
- Participants must ensure that their language and actions are free of personal attacks and disparaging personal remarks.
- When interpreting the words and actions of others, participants should always assume good intentions.
- Behavior that can be reasonably considered harassment will not be tolerated.

</div>
