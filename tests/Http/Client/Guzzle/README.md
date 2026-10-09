# Guzzle compatibility checks

These tests exercise the generated production proxies, callback context, native transport ownership and cleanup. They run through the normal framework suite. The focused Guzzle 7 CI job also covers affected HTTP, broadcasting, AWS and bootstrap paths; the ordinary unlocked jobs exercise the current dependency family.

The manual runner in `Fixtures/run-guzzle-promise-tests.php` runs Guzzle's unchanged promise tests. It is separate from Composer's test scripts because those upstream suites use PHPUnit 9, while Hypervel uses PHPUnit 13. Install each dependency family in its own temporary Composer project; never share its vendor directory with another checkout.

For example, set `COMPONENTS` to your checkout's absolute path:

```sh
COMPONENTS=/absolute/path/to/components
RUNTIME=/tmp/guzzle-promises-runtime
git clone --depth 1 --branch 3.0.2 https://github.com/guzzle/promises.git /tmp/guzzle-promises-tests
mkdir "$RUNTIME"
cd "$RUNTIME"
composer init --no-interaction --name=hypervel/guzzle-promises-check
composer config repositories.components path "$COMPONENTS"
composer config minimum-stability dev
composer config prefer-stable true
composer require --no-interaction 'hypervel/components:@dev' \
  'guzzlehttp/guzzle:8.2.0' 'guzzlehttp/promises:3.0.2' 'guzzlehttp/psr7:3.1.0' \
  'phpunit/phpunit:^9.6.34'

php "$COMPONENTS/tests/Http/Client/Guzzle/Fixtures/run-guzzle-promise-tests.php" \
  "$RUNTIME/vendor/autoload.php" /tmp/guzzle-promises-tests coroutine
```

Repeat with `outside` to exercise production integration outside coroutines, and `stock` for the untouched Guzzle baseline. Both integrated modes check that the queue and all six Promise method proxies are installed. They omit only `UtilsTest::testReturnsTrampoline`, which requires the concrete stock `TaskQueue` class rather than its replaceable interface. The stock run retains that assertion; Hypervel's queue tests cover installation and identity under integration.

For the other supported family, use a separate project and test clone with Guzzle 7.15.5, promises 2.5.3 and PSR-7 2.13.1. Run all three modes. When updating dependencies, use matching promise source tags and record the exact resolved versions. Do not change upstream assertions to make a failure pass; investigate the behavior first.

Remove temporary projects and clones after verification. Performance measurements have their own [harness](../../../Benchmarks/GuzzleOwnership/README.md) and require an idle machine.
