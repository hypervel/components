<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan;

use Hypervel\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

use function dirname;
use function file_get_contents;
use function file_put_contents;
use function mkdir;
use function preg_match;
use function symlink;
use function unlink;

/**
 * Everything the booted application decides depends on the inputs it was built from, which no
 * analyzed file references: provider and helper code, configuration, the environment, routes,
 * and the classes and views the boot selected.
 */
class RuntimeInputsDependencyTest extends ResultCacheTestCase
{
    /**
     * The global type alias the alias fixture uses, and one file per job, so the main process and
     * several workers each compute the values.
     */
    private const string PARAMETERS = "typeAliases:\n    RenderView: 'Closure(view-string): void'\nparallel:\n    jobSize: 1";

    /**
     * The diagnostics of the unedited boot inputs.
     */
    private const array BOOT_DIAGNOSTICS = [
        'Usage.php:28 method.notFound',
        'Usage.php:33 staticMethod.notFound',
        'Usage.php:38 return.type',
    ];

    /**
     * The diagnostics of missing views, which the boot input tests leave alone.
     */
    private const array VIEW_DIAGNOSTICS = [
        'MailUsage.php:13 argument.type',
        'ViewUsage.php:11 argument.type',
        'ViewUsage.php:16 argument.type',
        'alias.php:12 argument.type',
    ];

    protected string $fixture = 'RuntimeInputs';

    public function testUnchangedRunsReanalyzeNothing(): void
    {
        // Each run rewrites phpstan.neon, so PHPStan recompiles its container, which embeds the run's environment.
        $applicationPath = $this->copyApplication();

        $this->assertDiagnostics(self::BOOT_DIAGNOSTICS, $this->analyse($applicationPath, parameters: self::PARAMETERS));

        $firstWarm = $this->analyse($applicationPath, parameters: self::PARAMETERS);
        $this->assertDiagnostics(self::BOOT_DIAGNOSTICS, $firstWarm);

        $secondWarm = $this->analyse($applicationPath, parameters: self::PARAMETERS);
        $this->assertDiagnostics(self::BOOT_DIAGNOSTICS, $secondWarm);
        $this->assertReanalyzed(0, $secondWarm);

        // Asserted last: PHPStan's class values can differ between the run that first reflected a
        // built-in ancestor's stub and later runs reading it from its cache.
        $this->assertReanalyzed(0, $firstWarm);
    }

    public function testBootInputEditsReanalyzeTheirConsumers(): void
    {
        $applicationPath = $this->copyApplication();
        $base = self::BOOT_DIAGNOSTICS;

        $this->assertDiagnostics($base, $this->analyse($applicationPath, parameters: self::PARAMETERS));

        // A macro registered by a provider.
        $this->edit($applicationPath . '/app/Providers/FixtureServiceProvider.php', 'fn (): int => 1', "fn (): string => ''");
        $this->assertDiagnostics([...$base, 'Usage.php:18 return.type'], $this->analyse($applicationPath, parameters: self::PARAMETERS));
        $this->edit($applicationPath . '/app/Providers/FixtureServiceProvider.php', "fn (): string => ''", 'fn (): int => 1');
        $this->assertDiagnostics($base, $this->analyse($applicationPath, parameters: self::PARAMETERS));

        // A mixin class only the provider references.
        $this->edit($applicationPath . '/app/StrMixin.php', 'static fn (): int => 1', "static fn (): string => ''");
        $this->assertDiagnostics([...$base, 'Usage.php:23 return.type'], $this->analyse($applicationPath, parameters: self::PARAMETERS));

        // The body of a registration helper the provider calls.
        $this->edit($applicationPath . '/app/Registrar.php', "=== 'french'", "!== 'french'");
        $this->assertDiagnostics(
            ['Usage.php:23 return.type', 'Usage.php:33 staticMethod.notFound', 'Usage.php:38 return.type'],
            $this->analyse($applicationPath, parameters: self::PARAMETERS),
        );

        // A facade root that lacked the method, which loads only when analysis resolves it.
        $this->edit($applicationPath . '/app/FacadeRoot.php', "{\n}", "{\n    public function shout(): string\n    {\n        return '';\n    }\n}");
        $this->assertDiagnostics(['Usage.php:23 return.type', 'Usage.php:38 return.type'], $this->analyse($applicationPath, parameters: self::PARAMETERS));

        // The body of the manager's getDefaultDriver(), which loads only when analysis resolves the manager.
        $this->edit($applicationPath . '/app/Exports/ExportManager.php', "return 'csv';", "return 'json';");
        $this->assertDiagnostics(['Usage.php:23 return.type'], $this->analyse($applicationPath, parameters: self::PARAMETERS));
        $this->edit($applicationPath . '/app/Exports/ExportManager.php', "return 'json';", "return 'csv';");
        $this->assertDiagnostics(['Usage.php:23 return.type', 'Usage.php:38 return.type'], $this->analyse($applicationPath, parameters: self::PARAMETERS));

        // An interface the bound manager subclass's driver creator takes its return type from.
        $this->edit($applicationPath . '/app/Exports/CreatesReportDrivers.php', '@return JsonDriver', '@return CsvDriver');
        $this->assertDiagnostics(
            ['BoundReportManager.php:14 return.type', 'ReportUsage.php:13 return.type', 'Usage.php:23 return.type', 'Usage.php:38 return.type'],
            $this->analyse($applicationPath, parameters: self::PARAMETERS),
        );
        $this->edit($applicationPath . '/app/Exports/CreatesReportDrivers.php', '@return CsvDriver', '@return JsonDriver');
        $this->assertDiagnostics(['Usage.php:23 return.type', 'Usage.php:38 return.type'], $this->analyse($applicationPath, parameters: self::PARAMETERS));

        // The method of a driver the manager forwards to, typed from the manager's declaration.
        $this->edit($applicationPath . '/app/Exports/CsvDriver.php', "export(): string\n    {\n        return '';", "export(): int\n    {\n        return 0;");
        $this->assertDiagnostics(['Usage.php:23 return.type'], $this->analyse($applicationPath, parameters: self::PARAMETERS));

        // The morph map, which a collection's loadMorph() reads.
        $this->edit($applicationPath . '/app/Providers/FixtureServiceProvider.php', "'post' => Post::class", "'post' => \\RuntimeInputs\\Models\\Video::class");
        $this->assertDiagnostics(['Usage.php:23 return.type', 'Usage.php:46 argument.type'], $this->analyse($applicationPath, parameters: self::PARAMETERS));

        // A route file and a provider binding, which decide the worker's lifetimes.
        $this->edit($applicationPath . '/routes/web.php', "'ok');", "'ok');\nRoute::get('/counter', [\\RuntimeInputs\\CounterController::class, 'index']);");
        $this->assertDiagnostics(
            ['CounterController.php:13 hypervel.workerSharedState', 'Usage.php:23 return.type', 'Usage.php:46 argument.type'],
            $this->analyse($applicationPath, parameters: self::PARAMETERS),
        );
        $this->edit($applicationPath . '/app/Providers/FixtureServiceProvider.php', 'Registrar::register($this->app);', "Registrar::register(\$this->app);\n        \$this->app->scoped(\\RuntimeInputs\\CounterController::class);");
        $this->assertDiagnostics(['Usage.php:23 return.type', 'Usage.php:46 argument.type'], $this->analyse($applicationPath, parameters: self::PARAMETERS));
    }

    public function testDynamicRelationRegistrationsReanalyzeTheirConsumers(): void
    {
        $applicationPath = $this->copyApplication();
        file_put_contents($applicationPath . '/app/RelationUsage.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace RuntimeInputs;

            use Hypervel\Database\Eloquent\Relations\HasMany;
            use RuntimeInputs\Models\Post;

            class RelationUsage
            {
                public function replies(Post $post): HasMany
                {
                    return $post->replies();
                }
            }
            PHP);

        $this->edit(
            $applicationPath . '/app/Providers/FixtureServiceProvider.php',
            "Relation::morphMap(['post' => Post::class]);",
            "Relation::morphMap(['post' => Post::class]);\n        Post::resolveRelationUsing('replies', static fn (Post \$post): \\Hypervel\\Database\\Eloquent\\Relations\\HasMany => \$post->hasMany(\\RuntimeInputs\\Models\\Comment::class));",
        );
        $this->assertDiagnostics(self::BOOT_DIAGNOSTICS, $this->analyse($applicationPath, parameters: self::PARAMETERS));

        // The call resolves to the registered relation alone, so only the registration's tracking notices the change.
        $this->edit(
            $applicationPath . '/app/Providers/FixtureServiceProvider.php',
            '\Hypervel\Database\Eloquent\Relations\HasMany => $post->hasMany(',
            '\Hypervel\Database\Eloquent\Relations\BelongsTo => $post->belongsTo(',
        );
        $this->assertDiagnostics([...self::BOOT_DIAGNOSTICS, 'RelationUsage.php:14 return.type'], $this->analyse($applicationPath, parameters: self::PARAMETERS));
    }

    public function testBootInputEditsSkipLookupsOnClassesWithoutMacros(): void
    {
        // PHPStan gives a file the dependencies recorded on the declarations of classes it depends on, as far as its
        // process has recorded them when the file finishes. One process keeps that order the same between runs, and
        // the first edit's reanalysis drops what the cold run gave files that never read those facts (Comment.php,
        // through Model), so the count is taken on the second edit.
        $applicationPath = $this->copyApplication();
        $parameters = "typeAliases:\n    RenderView: 'Closure(view-string): void'\nparallel:\n    maximumNumberOfProcesses: 1";

        $this->analyse($applicationPath, parameters: $parameters);
        $this->assertDiagnostics(self::BOOT_DIAGNOSTICS, $this->analyse($applicationPath, parameters: $parameters));

        $this->edit($applicationPath . '/app/Providers/FixtureServiceProvider.php', 'fn (): int => 1', 'fn (): int => 2');
        $this->analyse($applicationPath, parameters: $parameters);
        $this->edit($applicationPath . '/app/Providers/FixtureServiceProvider.php', 'fn (): int => 2', 'fn (): int => 3');
        $process = $this->analyse($applicationPath, parameters: $parameters);
        $this->assertSame(1, preg_match('/Result cache restored\. (\d+) files? will be reanalysed\./', $process->getErrorOutput(), $matches));

        // The lookup reaches the macro extension, but a class that is not Macroable can gain no macro from the boot.
        file_put_contents($applicationPath . '/app/PlainUsage.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace RuntimeInputs;

            class PlainUsage
            {
                public function lookup(Registrar $registrar): bool
                {
                    return method_exists($registrar, 'missing');
                }
            }
            PHP);
        $this->assertDiagnostics(self::BOOT_DIAGNOSTICS, $this->analyse($applicationPath, parameters: $parameters));

        $this->edit($applicationPath . '/app/Providers/FixtureServiceProvider.php', 'fn (): int => 3', 'fn (): int => 4');
        $this->assertReanalyzed((int) $matches[1], $this->analyse($applicationPath, parameters: $parameters));
    }

    public function testEnvironmentChangesReanalyzeTheirConsumers(): void
    {
        $applicationPath = $this->copyApplication();
        file_put_contents($applicationPath . '/.env', "FIXTURE_GREETER=\${DEPLOY_GREETER}\n");
        $unset = ['FIXTURE_GREETER' => false, 'DEPLOY_GREETER' => false];

        $this->assertContains('Usage.php:28 method.notFound', $this->diagnostics($this->analyse($applicationPath, parameters: self::PARAMETERS, environment: $unset), 1));

        // A variable only the process sets, which the configuration reads.
        $this->assertNotContains(
            'Usage.php:28 method.notFound',
            $this->diagnostics($this->analyse($applicationPath, parameters: self::PARAMETERS, environment: ['FIXTURE_GREETER' => 'french', 'DEPLOY_GREETER' => false]), 1),
        );
        $this->assertContains('Usage.php:28 method.notFound', $this->diagnostics($this->analyse($applicationPath, parameters: self::PARAMETERS, environment: $unset), 1));

        // A variable the environment file interpolates.
        $interpolated = ['FIXTURE_GREETER' => false, 'DEPLOY_GREETER' => 'french'];
        $this->assertNotContains('Usage.php:28 method.notFound', $this->diagnostics($this->analyse($applicationPath, parameters: self::PARAMETERS, environment: $interpolated), 1));

        $process = $this->analyse($applicationPath, parameters: self::PARAMETERS, environment: $interpolated);
        $this->assertNotContains('Usage.php:28 method.notFound', $this->diagnostics($process, 1));
        $this->assertReanalyzed(0, $process);

        // Reading every variable would make results depend on unrelated ones, such as a session's.
        $this->assertStringNotContainsString("env-pattern\t*", (string) file_get_contents($applicationPath . '/cache/resultCache.php'));

        // A computed name reads every variable, compared by digest only.
        $this->edit($applicationPath . '/config/fixture.php', "env('FIXTURE_GREETER'", "env(strtoupper('fixture_greeter')");
        $this->assertNotContains('Usage.php:28 method.notFound', $this->diagnostics($this->analyse($applicationPath, parameters: self::PARAMETERS, environment: $interpolated), 1));
        $this->assertStringContainsString("env-pattern\t*", (string) file_get_contents($applicationPath . '/cache/resultCache.php'));

        $process = $this->analyse($applicationPath, parameters: self::PARAMETERS, environment: $interpolated);
        $this->assertNotContains('Usage.php:28 method.notFound', $this->diagnostics($process, 1));
        $this->assertReanalyzed(0, $process);

        $unrelatedValue = 'runtime-inputs-unrelated-value';
        $process = $this->analyse($applicationPath, parameters: self::PARAMETERS, environment: [...$interpolated, 'RUNTIME_INPUTS_UNRELATED' => $unrelatedValue]);
        $this->assertNotContains('Usage.php:28 method.notFound', $this->diagnostics($process, 1));
        $this->assertMatchesRegularExpression('/Result cache restored\. [1-9]\d* files? will be reanalysed\./', $process->getErrorOutput());
        $this->assertStringNotContainsString($unrelatedValue, (string) file_get_contents($applicationPath . '/cache/resultCache.php'));

        // The environment selects which application boots.
        $process = $this->analyse($applicationPath, parameters: self::PARAMETERS, environment: [...$interpolated, 'APP_ENV' => 'staging']);
        $this->assertStringContainsString('Result cache not used because the metadata do not match', $process->getErrorOutput());
    }

    public function testRepointedEnvironmentFileLinksAreNoticed(): void
    {
        $applicationPath = $this->copyApplication();
        $unset = ['FIXTURE_GREETER' => false];
        mkdir($applicationPath . '/environments');
        file_put_contents($applicationPath . '/environments/english', "FIXTURE_GREETER=english\n");
        file_put_contents($applicationPath . '/environments/french', "FIXTURE_GREETER=french\n");
        symlink($applicationPath . '/environments/english', $applicationPath . '/.env');

        $this->assertDiagnostics(self::BOOT_DIAGNOSTICS, $this->analyse($applicationPath, parameters: self::PARAMETERS, environment: $unset));

        // The file the link pointed to is unchanged, so only the link's own path shows the new values.
        unlink($applicationPath . '/.env');
        symlink($applicationPath . '/environments/french', $applicationPath . '/.env');
        $french = ['Usage.php:33 staticMethod.notFound', 'Usage.php:38 return.type'];
        $this->assertDiagnostics($french, $this->analyse($applicationPath, parameters: self::PARAMETERS, environment: $unset));

        $process = $this->analyse($applicationPath, parameters: self::PARAMETERS, environment: $unset);
        $this->assertDiagnostics($french, $process);
        $this->assertReanalyzed(0, $process);
    }

    public function testCachedConfigurationSkipsTheEnvironmentFileAndConfigFiles(): void
    {
        $applicationPath = $this->copyApplication();
        $unset = ['FIXTURE_GREETER' => false];
        $this->cacheConfiguration($applicationPath);

        // The boot neither loads nor validates the environment file while configuration is cached.
        file_put_contents($applicationPath . '/.env', "BROKEN=\"unterminated\n");
        $this->assertDiagnostics(self::BOOT_DIAGNOSTICS, $this->analyse($applicationPath, parameters: self::PARAMETERS, environment: $unset));

        file_put_contents($applicationPath . '/.env', "FIXTURE_GREETER=french\n");
        file_put_contents($applicationPath . '/config/unused.php', "<?php\n\nreturn [];\n");
        $process = $this->analyse($applicationPath, parameters: self::PARAMETERS, environment: $unset);
        $this->assertDiagnostics(self::BOOT_DIAGNOSTICS, $process);
        $this->assertReanalyzed(0, $process);

        // Clearing the cache loads them again.
        unlink($applicationPath . '/bootstrap/cache/config.php');
        $this->assertDiagnostics(
            ['Usage.php:33 staticMethod.notFound', 'Usage.php:38 return.type'],
            $this->analyse($applicationPath, parameters: self::PARAMETERS, environment: $unset),
        );
    }

    public function testConfigDirectoriesThatStartOrStopExistingAreNoticed(): void
    {
        $applicationPath = $this->copyApplication();
        $parameters = self::PARAMETERS . "\nconfigDirectories:\n    - '{$applicationPath}/config'\n    - '{$applicationPath}/modules/*/config'\n    - '{$applicationPath}/extra/config'";
        file_put_contents($applicationPath . '/app/ConfigUsage.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace RuntimeInputs;

            class ConfigUsage
            {
                public function limit(): int
                {
                    return config('module.limit', 0);
                }
            }
            PHP);

        $this->assertDiagnostics(self::BOOT_DIAGNOSTICS, $this->analyse($applicationPath, parameters: $parameters));

        mkdir($applicationPath . '/modules/billing/config', 0777, true);
        file_put_contents($applicationPath . '/modules/billing/config/module.php', "<?php\n\nreturn ['limit' => 'none'];\n");
        $this->assertDiagnostics([...self::BOOT_DIAGNOSTICS, 'ConfigUsage.php:11 return.type'], $this->analyse($applicationPath, parameters: $parameters));

        (new Filesystem)->deleteDirectory($applicationPath . '/modules/billing');
        $this->assertDiagnostics(self::BOOT_DIAGNOSTICS, $this->analyse($applicationPath, parameters: $parameters));

        // A configured literal directory is watched before it exists.
        mkdir($applicationPath . '/extra/config', 0777, true);
        file_put_contents($applicationPath . '/extra/config/module.php', "<?php\n\nreturn ['limit' => 'none'];\n");
        $this->assertDiagnostics([...self::BOOT_DIAGNOSTICS, 'ConfigUsage.php:11 return.type'], $this->analyse($applicationPath, parameters: $parameters));
    }

    public function testViewNameChangesReanalyzeOnlyViewConsumers(): void
    {
        $applicationPath = $this->copyApplication();
        $viewsPath = $applicationPath . '/resources/views';

        $this->assertDiagnostics(self::BOOT_DIAGNOSTICS, $this->analyse($applicationPath, parameters: self::PARAMETERS));

        // A free function's parameter, a class member's, a namespaced view and a global alias's.
        file_put_contents($viewsPath . '/fixture-page.blade.php', 'Page');
        file_put_contents($viewsPath . '/mail-page.blade.php', 'Mail');
        file_put_contents($viewsPath . '/alias-view.blade.php', 'Alias');
        mkdir($applicationPath . '/resources/fixture-views');
        file_put_contents($applicationPath . '/resources/fixture-views/panel.blade.php', 'Panel');

        $process = $this->analyse($applicationPath, parameters: self::PARAMETERS);
        $this->assertDiagnostics(self::BOOT_DIAGNOSTICS, $process, []);
        $this->assertReanalyzed(4, $process);

        unlink($viewsPath . '/fixture-page.blade.php');
        $this->assertDiagnostics(self::BOOT_DIAGNOSTICS, $this->analyse($applicationPath, parameters: self::PARAMETERS), ['ViewUsage.php:11 argument.type']);

        file_put_contents($viewsPath . '/fixture-page.blade.php', 'Page');
        $this->assertDiagnostics(self::BOOT_DIAGNOSTICS, $this->analyse($applicationPath, parameters: self::PARAMETERS), []);

        // Which views exist does not depend on their contents.
        file_put_contents($viewsPath . '/welcome.blade.php', '<p>Welcome back</p>');
        $process = $this->analyse($applicationPath, parameters: self::PARAMETERS);
        $this->assertDiagnostics(self::BOOT_DIAGNOSTICS, $process, []);
        $this->assertReanalyzed(0, $process);
    }

    public function testLinkedTemplateEditsRecheckTheirTranslations(): void
    {
        $applicationPath = $this->copyApplication();

        // The view finder finds shared.panel through the link, so the translation rule checks it.
        mkdir($applicationPath . '/lang/en', 0777, true);
        file_put_contents($applicationPath . '/lang/en/fixture.php', "<?php\n\nreturn ['greeting' => 'Hello'];\n");
        mkdir($applicationPath . '/resources/shared');
        file_put_contents($applicationPath . '/resources/shared/panel.blade.php', "{{ __('fixture.missing') }}\n");
        symlink($applicationPath . '/resources/shared', $applicationPath . '/resources/views/shared');

        $this->assertDiagnostics(
            [...self::BOOT_DIAGNOSTICS, 'panel.blade.php:1 hypervel.missingTranslations'],
            $this->analyse($applicationPath, reuseCollectedDataRuleErrors: true, parameters: self::PARAMETERS),
        );

        // An edit that keeps the template's name runs the rule again.
        file_put_contents($applicationPath . '/resources/shared/panel.blade.php', "{{ __('fixture.greeting') }}\n");
        $this->assertDiagnostics(self::BOOT_DIAGNOSTICS, $this->analyse($applicationPath, reuseCollectedDataRuleErrors: true, parameters: self::PARAMETERS));
    }

    /**
     * Write the application's configuration cache as config:cache does.
     */
    private function cacheConfiguration(string $applicationPath): void
    {
        $process = new Process([PHP_BINARY, '-r', <<<'PHP'
            require $argv[1] . '/vendor/autoload.php';
            require $argv[2] . '/bootstrap/autoload.php';
            $app = require $argv[2] . '/bootstrap/app.php';
            $app->make(Hypervel\Contracts\Console\Kernel::class)->bootstrap();
            file_put_contents($app->getCachedConfigPath(), '<?php return ' . var_export($app->make('config')->all(), true) . ';' . PHP_EOL);
            PHP, dirname(__DIR__, 2), $applicationPath], $applicationPath, ['FIXTURE_GREETER' => false]);
        $process->mustRun();
    }

    /**
     * Assert the run's diagnostics: the given ones plus the missing-view ones.
     *
     * @param list<string> $expected
     * @param null|list<string> $views the missing-view diagnostics, all of them when null
     */
    private function assertDiagnostics(array $expected, Process $process, ?array $views = null): void
    {
        $expected = [...$expected, ...($views ?? self::VIEW_DIAGNOSTICS)];
        sort($expected);

        $this->assertSame($expected, $this->diagnostics($process, $expected === [] ? 0 : 1));
    }
}
