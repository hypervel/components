<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Console;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

class SyncSkeletonConfigTest extends TestCase
{
    protected string $root;

    protected Filesystem $files;

    protected array $rules;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = ParallelTesting::tempDir('SyncSkeletonConfigTest');
        $this->files = new Filesystem;
        $this->files->deleteDirectory($this->root);

        foreach (['src/foundation/config', 'src/foundation/config-stubs', 'skeletons/hypervel', 'skeletons/react-starter-kit', 'bin/sync-skeleton-config'] as $directory) {
            $this->files->makeDirectory($this->root . '/' . $directory, 0755, true);
        }

        $this->rules = [
            'exclude' => [],
            'stubs' => ['app.php' => ['omit' => ['providers', 'aliases']]],
            'skeletons' => ['hypervel' => [], 'react-starter-kit' => []],
        ];

        $this->files->put($this->root . '/src/foundation/config/app.php', <<<'PHP'
<?php

declare(strict_types=1);

use Example\Provider;
use Example\Facade;
use Example\Logger;

return [
    // Keep this comment and expression exactly.
    'name' => env('APP_NAME', 'Example'),
    'logger' => Logger::class,

    /* Providers are omitted from published config. */
    'providers' => Provider::defaults(),

    /* Aliases are omitted too. */
    'aliases' => Facade::defaults(),
];

PHP);
        $this->files->put($this->root . '/src/foundation/config/auth.php', <<<'PHP'
<?php

declare(strict_types=1);

return [
    'defaults' => [
        // Starter kits can choose their default guard.
        'guard' => env('AUTH_GUARD', 'web'),
    ],
    'model' => App\Models\User::class, // @phpstan-ignore class.notFound
    'literal' => '// @phpstan-ignore class.notFound',
];

PHP);
    }

    protected function tearDown(): void
    {
        $this->files->deleteDirectory($this->root);

        parent::tearDown();
    }

    public function testSyncGeneratesStubsAndBothSkeletonsWithoutExecutingConfigs(): void
    {
        $source = $this->files->get($this->root . '/src/foundation/config/app.php');
        $this->assertSuccessful($this->runScript());
        $stub = $this->files->get($this->root . '/src/foundation/config-stubs/app.php');

        $this->assertStringContainsString("    // Keep this comment and expression exactly.\n    'name' => env('APP_NAME', 'Example'),", $stub);
        $this->assertStringContainsString('use Example\Logger;', $stub);
        $this->assertStringContainsString("declare(strict_types=1);\n\nuse Example\\Logger;", $stub);
        $this->assertStringNotContainsString('Provider', $stub);
        $this->assertStringNotContainsString('Facade', $stub);
        $this->assertStringNotContainsString('Aliases are omitted', $stub);
        $this->assertSame($source, $this->files->get($this->root . '/src/foundation/config/app.php'));

        foreach (['hypervel', 'react-starter-kit'] as $skeleton) {
            $this->assertSame($stub, $this->files->get($this->root . "/skeletons/{$skeleton}/config/app.php"));
            $auth = $this->files->get($this->root . "/skeletons/{$skeleton}/config/auth.php");
            $this->assertStringContainsString("'model' => App\\Models\\User::class,\n", $auth);
            $this->assertStringContainsString("'literal' => '// @phpstan-ignore class.notFound'", $auth);
        }

        $this->assertSuccessful($this->runScript('--check'));
    }

    public function testCheckReportsSkeletonDriftWithoutOverwritingIt(): void
    {
        $this->assertSuccessful($this->runScript());
        $path = $this->root . '/skeletons/react-starter-kit/config/auth.php';
        $this->files->put($path, 'outdated skeleton');
        $process = $this->runScript('--check');

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('Out of sync: skeletons/react-starter-kit/config/auth.php', $process->getErrorOutput());
        $this->assertSame('outdated skeleton', $this->files->get($path));
        $this->assertSuccessful($this->runScript());
        $this->assertSuccessful($this->runScript('--check'));
    }

    public function testInvalidSourceSyntaxReportsTheConfigBeforeWritingAnything(): void
    {
        $this->files->put($this->root . '/src/foundation/config/auth.php', '<?php return [');
        $process = $this->runScript();

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('Invalid config syntax for hypervel/auth.php', $process->getErrorOutput());
        $this->assertStringContainsString('bin/sync-skeleton-config/README.md', $process->getErrorOutput());
        $this->assertFileDoesNotExist($this->root . '/src/foundation/config-stubs/app.php');
    }

    public function testSkeletonRulesApplyAfterSharedStubRules(): void
    {
        $this->rules['skeletons']['react-starter-kit'] = [
            'app.php' => ['omit' => ['logger']],
            'auth.php' => ['replace' => ['defaults.guard' => "env('REACT_GUARD', 'web')"]],
        ];
        $this->assertSuccessful($this->runScript());

        $reactApp = $this->files->get($this->root . '/skeletons/react-starter-kit/config/app.php');
        $this->assertStringNotContainsString('Logger', $reactApp);
        $this->assertStringNotContainsString('providers', $reactApp);
        $this->assertStringContainsString('Logger', $this->files->get($this->root . '/skeletons/hypervel/config/app.php'));
        $this->assertStringContainsString("// Starter kits can choose their default guard.\n        'guard' => env('REACT_GUARD', 'web'),", $this->files->get($this->root . '/skeletons/react-starter-kit/config/auth.php'));
        $this->assertStringContainsString("env('AUTH_GUARD', 'web')", $this->files->get($this->root . '/skeletons/hypervel/config/auth.php'));
    }

    public function testCheckReportsEveryMissingOutputAndExplainsHowToFixItWithoutWriting(): void
    {
        $process = $this->runScript('--check');

        $this->assertSame(1, $process->getExitCode());

        foreach (['src/foundation/config-stubs/app.php', 'skeletons/hypervel/config/app.php', 'skeletons/react-starter-kit/config/auth.php'] as $path) {
            $this->assertStringContainsString($path, $process->getErrorOutput());
            $this->assertFileDoesNotExist($this->root . '/' . $path);
        }

        $this->assertStringContainsString('composer sync:skeleton-config', $process->getErrorOutput());
        $this->assertStringContainsString('bin/sync-skeleton-config/README.md', $process->getErrorOutput());
        $this->assertStringContainsString('bin/sync-skeleton-config/rules.php', $process->getErrorOutput());
        $this->assertStringContainsString('bin/sync-skeleton-config/sync.php', $process->getErrorOutput());
        $this->assertDirectoryDoesNotExist($this->root . '/skeletons/hypervel/config');
    }

    public function testCheckDoesNotModifyOutdatedFilesAndSyncDoesNotRewriteMatchingFiles(): void
    {
        $this->assertSuccessful($this->runScript());
        $matching = $this->root . '/skeletons/hypervel/config/auth.php';
        touch($matching, 1000000000);
        $this->assertSuccessful($this->runScript());
        clearstatcache();
        $this->assertSame(1000000000, filemtime($matching));

        $outdated = $this->root . '/src/foundation/config-stubs/app.php';
        $this->files->put($outdated, 'outdated');
        touch($outdated, 1000000000);
        $process = $this->runScript('--check');

        $this->assertSame(1, $process->getExitCode());
        $this->assertSame('outdated', $this->files->get($outdated));
        clearstatcache();
        $this->assertSame(1000000000, filemtime($outdated));
        $this->assertStringContainsString('src/foundation/config-stubs/app.php', $process->getErrorOutput());
        $this->assertStringNotContainsString('Out of sync: skeletons/', $process->getErrorOutput());

        $this->assertSuccessful($this->runScript());
        $this->assertSuccessful($this->runScript('--check'));
    }

    public function testRemovedStubRulesRemoveObsoleteOverridesButPreserveSkeletonOwnedConfigs(): void
    {
        $this->assertSuccessful($this->runScript());
        $custom = $this->root . '/skeletons/hypervel/config/custom.php';
        $this->files->put($custom, '<?php return [];');
        $this->rules['stubs'] = [];

        $this->assertSame(1, $this->runScript('--check')->getExitCode());
        $this->assertFileExists($this->root . '/src/foundation/config-stubs/app.php');
        $this->assertSuccessful($this->runScript());
        $this->assertFileDoesNotExist($this->root . '/src/foundation/config-stubs/app.php');
        $this->assertStringContainsString('Provider::defaults()', $this->files->get($this->root . '/skeletons/hypervel/config/app.php'));
        $this->assertSame('<?php return [];', $this->files->get($custom));
    }

    public function testSharedExclusionsRemoveOnlySkeletonCopiesAndCanBeReversed(): void
    {
        $this->assertSuccessful($this->runScript());
        $source = $this->files->get($this->root . '/src/foundation/config/app.php');
        $stub = $this->files->get($this->root . '/src/foundation/config-stubs/app.php');
        $this->rules['exclude'] = ['app.php', 'auth.php'];
        $process = $this->runScript('--check');

        $this->assertSame(1, $process->getExitCode());

        foreach (['hypervel', 'react-starter-kit'] as $skeleton) {
            foreach (['app.php', 'auth.php'] as $name) {
                $path = "skeletons/{$skeleton}/config/{$name}";
                $this->assertStringContainsString("Out of sync: {$path} (must be absent)", $process->getErrorOutput());
                $this->assertFileExists($this->root . '/' . $path);
            }
        }

        $this->assertSuccessful($this->runScript());

        foreach (['hypervel', 'react-starter-kit'] as $skeleton) {
            foreach (['app.php', 'auth.php'] as $name) {
                $this->assertFileDoesNotExist($this->root . "/skeletons/{$skeleton}/config/{$name}");
            }
        }

        $this->assertSame($source, $this->files->get($this->root . '/src/foundation/config/app.php'));
        $this->assertSame($stub, $this->files->get($this->root . '/src/foundation/config-stubs/app.php'));
        $this->assertFileExists($this->root . '/src/foundation/config/auth.php');
        $this->assertSuccessful($this->runScript('--check'));

        $this->rules['exclude'] = [];
        $this->assertSame(1, $this->runScript('--check')->getExitCode());
        $this->assertSuccessful($this->runScript());

        foreach (['hypervel', 'react-starter-kit'] as $skeleton) {
            $this->assertSame($stub, $this->files->get($this->root . "/skeletons/{$skeleton}/config/app.php"));
            $this->assertFileExists($this->root . "/skeletons/{$skeleton}/config/auth.php");
        }

        $this->assertSuccessful($this->runScript('--check'));
    }

    public function testExcludedConfigsAreNotCreatedOnAnInitialSync(): void
    {
        $this->rules['exclude'] = ['auth.php'];
        $this->assertSuccessful($this->runScript());

        foreach (['hypervel', 'react-starter-kit'] as $skeleton) {
            $this->assertFileDoesNotExist($this->root . "/skeletons/{$skeleton}/config/auth.php");
            $this->assertFileExists($this->root . "/skeletons/{$skeleton}/config/app.php");
        }

        $this->assertSuccessful($this->runScript('--check'));
    }

    #[DataProvider('invalidRules')]
    public function testInvalidRulesFailBeforeAnyOutputIsWritten(array $rules, string $message): void
    {
        $this->rules = $rules;
        $process = $this->runScript();

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString($message, $process->getErrorOutput());
        $this->assertStringContainsString('bin/sync-skeleton-config/README.md', $process->getErrorOutput());
        $this->assertFileDoesNotExist($this->root . '/src/foundation/config-stubs/app.php');
        $this->assertDirectoryDoesNotExist($this->root . '/skeletons/hypervel/config');
    }

    /**
     * Provide invalid rules that must not silently weaken the check.
     */
    public static function invalidRules(): array
    {
        return [
            'missing key' => [['stubs' => ['app.php' => ['omit' => ['missing']]], 'skeletons' => []], 'stubs/app.php:missing'],
            'missing source' => [['stubs' => ['missing.php' => []], 'skeletons' => []], 'missing config: missing.php'],
            'unknown operation' => [['stubs' => ['app.php' => ['omti' => []]], 'skeletons' => []], 'Unknown rule in stubs/app.php: omti'],
            'unknown section' => [['stub' => [], 'skeletons' => []], 'Unknown rule in rules: stub'],
            'missing section' => [['stubs' => []], 'Rules must define an array for skeletons'],
            'missing skeleton' => [['stubs' => [], 'skeletons' => ['missing' => []]], 'Skeleton directory does not exist: missing'],
            'missing skeleton config' => [['stubs' => [], 'skeletons' => ['hypervel' => ['missing.php' => []]]], 'missing config: hypervel/missing.php'],
            'non-array parent' => [['stubs' => [], 'skeletons' => ['hypervel' => ['app.php' => ['omit' => ['name.child']]]]], 'non-literal array: hypervel/app.php:name.child'],
            'missing replacement key' => [['stubs' => [], 'skeletons' => ['hypervel' => ['auth.php' => ['replace' => ['defaults.missing' => "'api'"]]]]], 'missing key: hypervel/auth.php:defaults.missing'],
            'multiple replacement expressions' => [['stubs' => [], 'skeletons' => ['hypervel' => ['auth.php' => ['replace' => ['defaults.guard' => "'api'; exit()"]]]]], 'Replacement must be one PHP expression'],
            'invalid replacement syntax' => [['stubs' => [], 'skeletons' => ['hypervel' => ['auth.php' => ['replace' => ['defaults.guard' => '[']]]]], 'Invalid replacement for hypervel/auth.php:defaults.guard'],
            'misspelled exclusion' => [['exclude' => ['missing.php'], 'stubs' => [], 'skeletons' => []], 'Exclusion refers to missing framework config: missing.php'],
            'excluded skeleton rule' => [['exclude' => ['auth.php'], 'stubs' => [], 'skeletons' => ['hypervel' => ['auth.php' => ['omit' => ['model']]]]], 'Skeleton rule refers to excluded config: hypervel/auth.php'],
            'non-array exclusions' => [['exclude' => 'auth.php', 'stubs' => [], 'skeletons' => []], 'Rule must be an array: rules:exclude'],
            'non-array omissions' => [['stubs' => ['app.php' => ['omit' => 'providers']], 'skeletons' => []], 'Rule must be an array: stubs/app.php:omit'],
            'non-array skeleton rules' => [['stubs' => [], 'skeletons' => ['hypervel' => null]], 'Skeleton rules must be an array: hypervel'],
        ];
    }

    /**
     * Run the real CLI against the isolated repository fixture.
     */
    protected function runScript(string ...$arguments): Process
    {
        $this->files->put($this->root . '/bin/sync-skeleton-config/rules.php', '<?php return ' . var_export($this->rules, true) . ';');
        $process = new Process([
            PHP_BINARY,
            dirname(__DIR__, 3) . '/bin/sync-skeleton-config/sync.php',
            '--root=' . $this->root,
            ...$arguments,
        ], timeout: 30);
        $process->run();

        return $process;
    }

    /**
     * Assert the process succeeded with its diagnostic output on failure.
     */
    protected function assertSuccessful(Process $process): void
    {
        $this->assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
    }
}
