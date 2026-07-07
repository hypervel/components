# Contribution Guide
[[toc]]

## Bug Reports

To encourage active collaboration, Hypervel strongly encourages pull requests, not just bug reports. Pull requests will only be reviewed when marked as "ready for review" (not in the "draft" state) and all tests for new features are passing. Lingering, non-active pull requests left in the "draft" state will be closed after a few days.

However, if you file a bug report, your issue should contain a title and a clear description of the issue. You should also include as much relevant information as possible and a code sample that demonstrates the issue. The goal of a bug report is to make it easy for yourself - and others - to replicate the bug and develop a fix.

Remember, bug reports are created in the hope that others with the same problem will be able to collaborate with you on solving it. Do not expect that the bug report will automatically see any activity or that others will jump to fix it. Creating a bug report serves to help yourself and others start on the path of fixing the problem. If you want to chip in, you can help out by fixing [any bugs listed in our issue trackers](https://github.com/hypervel/components/issues). You must be authenticated with GitHub to view all of Hypervel's issues.

If you notice improper DocBlock, PHPStan, or IDE warnings while using Hypervel, do not create a GitHub issue. Instead, please submit a pull request to fix the problem.

## Support Questions

Hypervel's GitHub issue trackers are not intended to provide Hypervel help or support. Instead, use [GitHub Discussions](https://github.com/hypervel/components/discussions)

## Which Branch?

All bug fixes should be sent to the latest version that supports bug fixes (currently **0.1**). Bug fixes should never be sent to the **main** branch unless they fix features that exist only in the upcoming release.

**Minor** features that are **fully backward compatible** with the current release may be sent to the latest stable branch (currently 0.1).

**Major new** features or features with breaking changes should always be sent to the **main** branch, which contains the upcoming release.

## Security Vulnerabilities

If you discover a security vulnerability within Hypervel, please send an email to Albert Chen at [albert@hypervel.org](mailto:albert@hypervel.org). All security vulnerabilities will be promptly addressed.

## Coding Style

Hypervel follows the [PSR-12](https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-12-extended-coding-style-guide-meta.md) coding standard and the [PSR-4](https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-4-autoloader.md) autoloading standard.

### Strict Types

Always use native types for type hinting, and declare strict types at the beginning of PHP files.

```php
declare(strict_types=1);

/**
 * Execute the job.
 */
public function handle(AudioProcessor $processor): void
{
    //
}
```

### PHPDoc

Below is an example of a valid Hypervel documentation block if you need to mark additional info for the type hinting. Note that the `@param` attribute is followed by two spaces, the argument type, two more spaces, and finally the variable name:

```php
/**
 * E-mail the results of the scheduled operation if it fails.
 *
 * @param array|mixed $addresses
 */
public function emailOutputOnFailure(mixed $addresses): static
```

And when the native type is generic, please specify the generic type through the use of the `@param` or `@return` attributes:

```php
/**
 * Get the attachments for the message.
 *
 * @return array<int, \Hypervel\Mail\Mailables\Attachment>
 */
public function attachments(): array
{
    return [
        Attachment::fromStorage('/path/to/file'),
    ];
}
```

## Code of Conduct

The Hypervel code of conduct is derived from the Laravel code of conduct. Any violations of the code of conduct may be reported to Albert Chen (albert@hypervel.org):

* Participants will be tolerant of opposing views.
* Participants must ensure that their language and actions are free of personal attacks and disparaging personal remarks.
* When interpreting the words and actions of others, participants should always * assume good intentions.
* Behavior that can be reasonably considered harassment will not be tolerated.