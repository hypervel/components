# Contribution Guide

- [Bug Reports](#bug-reports)
- [Support Questions](#support-questions)
- [Accepted Contributions](#accepted-contributions)
- [Porting Laravel Functionality](#porting-laravel-functionality)
- [Automated Reviews](#automated-reviews)
- [Which Branch?](#which-branch)
- [Quality Checks](#quality-checks)
- [Compiled Assets](#compiled-assets)
- [AI-Generated Contributions](#ai-generated-contributions)
- [Security Vulnerabilities](#security-vulnerabilities)
- [Coding Style](#coding-style)
    - [PHPDoc](#phpdoc)
- [Code of Conduct](#code-of-conduct)

<a name="bug-reports"></a>
## Bug Reports

To encourage active collaboration, Hypervel strongly encourages pull requests, not just bug reports. Pull requests are always prioritized before issue-only reports. Pull requests will only be reviewed when marked as "ready for review" (not in the "draft" state) and all required checks are passing. Lingering, non-active pull requests left in the "draft" state may be closed after a few days.

> [!NOTE]
> Bug reports should include a failing test, a minimal reproduction, or enough code to reproduce the issue. Issues that cannot be reproduced may be closed until a reproduction is provided.

However, if you file a bug report, your issue should contain a title and a clear description of the issue. You should also include as much relevant information as possible and a code sample that demonstrates the issue. The goal of a bug report is to make it easy for yourself - and others - to replicate the bug and develop a fix.

Remember, bug reports are created in the hope that others with the same problem will be able to collaborate with you on solving it. Do not expect that the bug report will automatically see any activity or that others will jump to fix it. Creating a bug report serves to help yourself and others start on the path of fixing the problem.

If you notice improper DocBlock, PHPStan, or IDE warnings while using Hypervel, do not create a GitHub issue. Instead, please submit a pull request to fix the problem.

The Hypervel source code is managed on GitHub in the [hypervel/components](https://github.com/hypervel/components) repository.

<a name="support-questions"></a>
## Support Questions

Hypervel's GitHub issue trackers are not intended to provide Hypervel help or support. Instead, use [GitHub Discussions](https://github.com/hypervel/components/discussions) for support questions, ideas, and feature requests.

<a name="accepted-contributions"></a>
## Accepted Contributions

Hypervel is maintained by a small core team and intentionally stays close to Laravel's public API. To keep the project maintainable, pull requests should be focused, thoroughly tested, and limited to one of the following categories:

<div class="content-list" markdown="1">

- Porting new or missing Laravel functionality to Hypervel.
- Fixing bugs.
- Improving performance.

</div>

Bug fixes should include a regression test when practical and explain the root cause of the bug. Performance improvements should describe the improvement and include benchmark results or other evidence when the change is not obvious.

New framework features that do not exist in Laravel are generally not accepted unless they solve a Hypervel-specific problem such as coroutine safety, Swoole integration, or performance in long-lived workers. If you are unsure whether a change fits Hypervel's direction, start a [GitHub Discussion](https://github.com/hypervel/components/discussions) before opening a pull request.

<a name="porting-laravel-functionality"></a>
## Porting Laravel Functionality

Pull requests that port Laravel functionality must make it easy to review the port against the upstream source. Include a link to the corresponding Laravel pull request, classes, tests, or source code being ported.

Laravel ports must include the relevant Laravel tests, updated for Hypervel's namespaces and architecture. If Hypervel intentionally differs from Laravel because of coroutine safety, Swoole, or an unsupported driver or integration, explain that difference in the pull request description.

<a name="automated-reviews"></a>
## Automated Reviews

Hypervel pull requests are first reviewed by Greptile and CodeRabbit. These tools are useful for identifying initial correctness, maintainability, and style issues before a core team member spends time on the review.

Please resolve the issues flagged by these tools before requesting human review. This keeps pull requests in a better state and makes the review workload manageable for Hypervel's small core team.

<a name="which-branch"></a>
## Which Branch?

Bug fixes and backward-compatible improvements for the current release should be sent to the current version branch (currently `0.4`).

Breaking changes or work intended for the next minor release should be sent to the `main` branch. Hypervel is pre-1.0, so minor releases may include breaking changes, but those changes should still be intentional and documented.

<a name="quality-checks"></a>
## Quality Checks

All PHPUnit tests, PHPStan static analysis, and php-cs-fixer style checks must pass before a pull request can be reviewed. The easiest way to verify everything locally without modifying files is to run:

```shell
composer check
```

If you want to automatically fix coding style before running analysis and the parallel test suite, run:

```shell
composer fix
```

You may also run the individual checks directly:

```shell
composer test
composer test:parallel
composer analyse
composer lint
composer lint:fix
```

Some checks only run in the CI pipeline. If CI fails, please review the failure and update your pull request before requesting another review.

<a name="compiled-assets"></a>
## Compiled Assets

Hypervel packages such as Horizon and Telescope include JavaScript and CSS assets for their dashboards. If you are submitting a change that affects dashboard source files, do not commit the generated `dist` files unless a maintainer asks you to do so. Due to their size, compiled assets cannot realistically be reviewed with the same care as source files. This could be exploited as a way to inject malicious code into Hypervel. In order to defensively prevent this, compiled assets will be generated and committed by Hypervel maintainers when needed.

<a name="ai-generated-contributions"></a>
## AI-Generated Contributions

We appreciate every pull request submitted to Hypervel. However, contributions that are primarily AI-generated without thoughtful human review and consideration are not acceptable.

If you choose to use AI tools to assist with your contribution, the resulting code **must** be thoroughly reviewed, tested, and understood by you before submitting.

**Mass opening issues or pull requests that are entirely AI-generated will not be tolerated.** Such pull requests will be closed without review, and the contributing user may be blocked from the repository.

We encourage contributors to familiarize themselves with the existing codebase, engage with the community, and submit pull requests that reflect their own understanding and careful consideration of the problem they are solving.

<a name="security-vulnerabilities"></a>
## Security Vulnerabilities

If you discover a security vulnerability within Hypervel, please send an email to Albert Chen at <a href="mailto:albert@hypervel.org">albert@hypervel.org</a>. All security vulnerabilities will be promptly addressed.

<a name="coding-style"></a>
## Coding Style

Hypervel follows the [PSR-4](https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-4-autoloader.md) autoloading standard and uses php-cs-fixer to enforce coding style. All PHP files should use strict types, native type declarations, and modern PHP 8.4+ features where appropriate.

All parameters, return values, properties, and closure signatures should be fully typed unless PHP itself cannot express the type.

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

However, when the native type is generic, please specify the generic type through the use of the `@param` or `@return` attributes:

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

<a name="code-of-conduct"></a>
## Code of Conduct

The Hypervel code of conduct is derived from the Ruby code of conduct. Any violations of the code of conduct may be reported to Albert Chen (albert@hypervel.org):

<div class="content-list" markdown="1">

- Participants will be tolerant of opposing views.
- Participants must ensure that their language and actions are free of personal attacks and disparaging personal remarks.
- When interpreting the words and actions of others, participants should always assume good intentions.
- Behavior that can be reasonably considered harassment will not be tolerated.

</div>
