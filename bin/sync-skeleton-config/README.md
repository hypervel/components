# Sync skeleton configuration

Maintain shared defaults in `src/foundation/config/`. This tool generates publishing overrides in `src/foundation/config-stubs/` and copies the appropriate configs into the skeletons registered in `rules.php`.

Run from the components repository root after installing Composer dependencies:

```sh
composer sync:skeleton-config
composer check:skeleton-config
```

Both commands run `sync.php`. The check command passes `--check`: it reports every missing, outdated, or obsolete managed output and exits with status 1 without writing files. PR tests and split/release workflows run this check before publishing.

`composer fix` also runs sync after formatting and before analysis and tests. Review and commit any generated changes it produces.

## Fix a workflow failure

1. Read the reported filenames or rule errors. Edit framework defaults in `src/foundation/config/` and intentional differences in `bin/sync-skeleton-config/rules.php`.
2. Run `composer sync:skeleton-config`.
3. Review the generated diff, then run `composer check:skeleton-config` and the focused tests below.
4. Commit the source, rules, and generated changes together.

Do not fix drift by editing generated files directly. Split and release workflows only check; they never regenerate or commit configuration.

## Rules

`rules.php` has three sections:

- `exclude`: framework config filenames to omit from every skeleton. Sync removes existing skeleton copies; check reports them as files that must be absent. The framework configs and publishing stubs remain available to `config:publish`. The list is empty by default.
- `stubs`: differences from framework defaults for files published by `config:publish` when framework config merging is enabled. Only files listed here get a generated stub. Removing a stub rule removes its generated override on the next sync.
- `skeletons`: direct skeleton directory names and their additional rules. Each registered skeleton receives every non-excluded framework config, using the generated publishing stub when one exists. Use `files` for additional differences and `preserve` for skeleton-only config filenames. An empty rules array means no additional differences or preserved files.

For example:

```php
return [
    'exclude' => [],
    'stubs' => [
        'app.php' => [
            'omit' => ['providers', 'aliases'],
        ],
    ],
    'skeletons' => [
        'hypervel' => [],
        'react-starter-kit' => [
            'files' => [
                'auth.php' => [
                    'replace' => ['defaults.guard' => "env('AUTH_GUARD', 'web')"],
                ],
            ],
        ],
    ],
];
```

The replacement above illustrates the syntax; it is not a required React setting. Add rules only for deliberate differences and explain their purpose with a short comment. For example, adding `'broadcasting.php'` to `exclude` would remove that config from both skeletons while keeping it publishable. Removing the exclusion restores the generated copies. Exclusions must name existing framework configs, and skeleton rules cannot target excluded files.

`omit` lists existing keys to remove. `replace` maps existing keys to PHP source expressions, represented as strings. Dotted keys address nested literal arrays. Rules cannot traverse a function call or other computed value. Missing keys, missing source files, unknown operations, and unregistered directory names in rules fail rather than silently doing nothing. Register a new skeleton by creating its directory and adding its name under `skeletons`; its `config/` directory is created by sync.

The tool parses PHP without executing it, preserving environment expressions, comments, and formatting. Omitting an entry removes its attached comments and any imports no longer used by the remaining config. The exact framework-only `// @phpstan-ignore class.notFound` comment is stripped from generated files; other comments and string contents are retained.

Both sync and check reject unexpected skeleton config files before writing anything. When removing or renaming a framework config, remove its old skeleton copies too; the error lists any that remain.

For a config owned by a skeleton, such as an optional package's `fortify.php`, add its filename to that skeleton's `preserve` list. The file must already exist and must not also be a framework config. Its contents remain untouched by sync:

```php
'hypervel' => [
    'preserve' => ['fortify.php'],
],
```

This keeps skeleton-only configuration explicit without deleting unrecognized files or tracking a separate generation manifest. Publishing stubs are fully generated: obsolete PHP stubs are removed automatically, while the directory's README is retained.

## Tests

```sh
vendor/bin/phpunit --no-progress tests/Foundation/Console/SyncSkeletonConfigTest.php
```

Tests invoke the real script against temporary repository fixtures using `--root=/path/to/fixture`. The script and its Composer dependencies still come from this checkout; config sources, rules, and generated outputs come from the supplied root. Normal use does not need `--root`.
