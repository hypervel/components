# PHPUnit runners, generators and documentation

Hypervel tests already use PHPUnit and ParaTest. Remove Pest detection, test generation, Testbench helpers and examples so installing another package cannot silently change which executable or stub Artisan selects.

## Runners and Testbench

- In `TestCommandBase::binary()`, select the application's PHPUnit executable for serial runs and ParaTest for parallel runs. Preserve the PHP binary and phpdbg arguments. Both the application and package test commands use this base. Preserve coverage formatting for parallel runs, configuration discovery, argument forwarding, profiling, exit codes and cleanup.
- Remove the Pest-specific Testbench trait and its Workbench use. Workbench already imports the concerns that supply the PHPUnit lifecycle and test-case checks. Fixture discovery continues to use the PHPUnit class filename. Trim the affected test harnesses and keep the assertions for both supported binaries.

## Generators

- Keep `--test` and `--phpunit` for companion tests. Remove `--pest`, its forwarding and its three stubs. Use the existing PHPUnit feature, unit and view stubs with application overrides taking precedence. Preserve the public `make:test --phpunit` option and update its command signature fixture.
- Correct `make:model Foo --controller --phpunit` in its own commit. The model generator previously omitted the PHPUnit request when dispatching the controller generator. Verify both `tests/Feature/Models/FooTest.php` and `tests/Feature/Http/Controllers/FooControllerTest.php`. Once PHPUnit is the sole generator, forward either request to the controller as `--test`.
- Keep the regression for stripping a trailing `.php` without stripping it inside a directory name. With PHPUnit, generating `fixtures.php/SomeTest` must fail linting because the namespace contains a dot. Assert the diagnostic includes the preserved destination and the generator writes no invalid class.

## Documentation and fixtures

- Compare each of the 53 paired Pest and PHPUnit fences. Preserve unique teaching details, remove the Pest examples, and display their PHPUnit counterparts as ordinary PHP blocks. Use typed PHPUnit test methods in the six retained examples missing `: void`. Convert the two untagged Socialite tests and the API client expectation, keeping the original requests and assertions.
- Remove recommendations, links, table-of-contents entries and obsolete unsupported-feature notes. Explain the migration once in the Laravel porting guide. Record the cross-package exclusion under the existing global exclusions in `AGENTS.md` so later Laravel and Orchestra syncs respect it.
- Replace incidental sample package names in the Prompts grid and Composer argument tests. Preserve widths, grid assertions, package constraints, development dependency flag and process environment.

## Verification

1. Exercise each changed test file as it is updated. Check the real model/controller output and both serial and parallel runner paths.
2. Run `composer lint:fix`, `composer analyse`, the targeted generator, signature, testing, Testbench, Prompts and Composer tests, and `composer test:testbench` for the real package command.
3. Inspect all changed documentation fences, anchors and internal links. Search tracked filenames, file contents and PHP snippets for Pest symbols, CLI flags, stubs, package names and test DSL, including code without the framework name. Check the skeletons, manifests, CI and private consumers. Historical plans remain records of prior work.
4. Review the diff through callers and callees, obtain peer signoff, remove the completed backlog entry and commit whole files with the independent generator fix documented separately.
