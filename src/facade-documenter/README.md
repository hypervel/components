# Facade Documenter

Generates `@method` docblocks on Hypervel facades from their underlying proxy classes.

## Using in a third-party package

Install as a dev dependency:

```sh
composer require --dev hypervel/facade-documenter
```

Update the docblocks:

```sh
php -f vendor/bin/facade.php -- \
    Your\\Package\\Facades\\Foo \
    Your\\Package\\Facades\\Bar \
    ...
```

Lint the docblocks (no changes written, exit code reflects drift):

```sh
php -f vendor/bin/facade.php -- --lint \
    Your\\Package\\Facades\\Foo \
    Your\\Package\\Facades\\Bar \
    ...
```

## Using in the Hypervel components monorepo

This package is present in the components monorepo at `src/facade-documenter/`,
so no installation is required. Invoke the script directly from the monorepo root:

```sh
php -f src/facade-documenter/facade.php -- \
    Hypervel\\Support\\Facades\\App \
    Hypervel\\Support\\Facades\\Auth \
    ...
```

Linting works the same way:

```sh
php -f src/facade-documenter/facade.php -- --lint \
    Hypervel\\Support\\Facades\\App \
    Hypervel\\Support\\Facades\\Auth \
    ...
```
