<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Vite;
use Hypervel\Foundation\ViteException;
use Hypervel\Foundation\ViteFonts;
use Hypervel\Support\Facades\Vite as ViteFacade;
use Hypervel\Support\Str;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use JsonException;
use ReflectionMethod;

use function Hypervel\Coroutine\parallel;

class FoundationViteFontsTest extends TestCase
{
    protected string $tempDir;

    protected Filesystem $filesystem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new Filesystem;
        $this->tempDir = ParallelTesting::tempDir('FoundationViteFontsTest');
        $this->filesystem->deleteDirectory($this->tempDir);
        mkdir($this->tempDir, 0777, true);

        app()->usePublicPath($this->tempDir);
        app('config')->set('app.asset_url', 'https://example.com');
    }

    protected function tearDown(): void
    {
        $this->filesystem->deleteDirectory($this->tempDir);
        ViteFacade::flush();

        parent::tearDown();
    }

    public function testFontsReturnsEmptyStringWhenNoManifestExists(): void
    {
        $result = app(Vite::class)->fonts();

        $this->assertSame('', $result->toHtml());
    }

    public function testFontsReturnsEmptyStringWhenHotFileExistsButNoHotManifest(): void
    {
        $this->makeHotFile();

        $result = app(Vite::class)->fonts();

        $this->assertSame('', $result->toHtml());
    }

    public function testFontsRendersPreloadsAndStyleInBuildMode(): void
    {
        $this->makeFontsManifest();
        $this->makeFontsCssFile('build', 'assets/fonts-abc123.css', "@font-face { font-family: 'Inter'; src: url('../fonts/inter-400.woff2') format('woff2'); }");

        $result = app(Vite::class)->fonts();

        $this->assertStringContainsString(
            '<link rel="preload" as="font" href="https://example.com/build/assets/inter-400.woff2" type="font/woff2" crossorigin="anonymous" />',
            $result->toHtml()
        );
        $this->assertStringContainsString(
            "<style>\n@font-face { font-family: 'Inter'; src: url('../fonts/inter-400.woff2') format('woff2'); }\n</style>",
            $result->toHtml()
        );
    }

    public function testFontsRendersPreloadsBeforeStyle(): void
    {
        $this->makeFontsManifest();
        $this->makeFontsCssFile('build', 'assets/fonts-abc123.css', "@font-face { font-family: 'Inter'; }");

        $result = app(Vite::class)->fonts()->toHtml();

        $preloadPos = strpos($result, '<link rel="preload"');
        $stylePos = strpos($result, '<style>');

        $this->assertNotFalse($preloadPos);
        $this->assertNotFalse($stylePos);
        $this->assertLessThan($stylePos, $preloadPos);
    }

    public function testFontsRendersInHotMode(): void
    {
        $this->makeHotFile();
        $this->makeHotFontsManifest();

        $result = app(Vite::class)->fonts();

        $this->assertStringContainsString(
            '<link rel="preload" as="font" href="http://localhost:3000/__laravel_vite_plugin__/fonts/inter.woff2" type="font/woff2" crossorigin="anonymous" />',
            $result->toHtml()
        );
        $this->assertStringContainsString(
            "<style>\n@font-face { font-family: 'Inter'; src: url('http://localhost:3000/fonts/inter.woff2'); }\n</style>",
            $result->toHtml()
        );
    }

    public function testFontsRespectsCustomBuildDirectory(): void
    {
        $this->makeFontsManifest($this->defaultManifest(), 'custom-build');
        $this->makeFontsCssFile('custom-build', 'assets/fonts-abc123.css', "@font-face { font-family: 'Inter'; }");

        ViteFacade::useBuildDirectory('custom-build');

        $result = app(Vite::class)->fonts();

        $this->assertStringContainsString(
            'href="https://example.com/custom-build/assets/inter-400.woff2"',
            $result->toHtml()
        );
    }

    public function testFontsRespectsCreateAssetPathsUsing(): void
    {
        $this->makeFontsManifest();
        $this->makeFontsCssFile('build', 'assets/fonts-abc123.css', "@font-face { font-family: 'Inter'; }");

        ViteFacade::createAssetPathsUsing(fn ($path) => "https://cdn.example.com/{$path}");

        $result = app(Vite::class)->fonts();

        $this->assertStringContainsString(
            'href="https://cdn.example.com/build/assets/inter-400.woff2"',
            $result->toHtml()
        );

        ViteFacade::createAssetPathsUsing(null);
    }

    public function testFontsAppliesCspNonceToStyleAndPreloads(): void
    {
        Str::createRandomStringsUsing(fn ($length) => "random-string-with-length:{$length}");
        $this->makeFontsManifest();
        $this->makeFontsCssFile('build', 'assets/fonts-abc123.css', "@font-face { font-family: 'Inter'; }");

        ViteFacade::useCspNonce();

        $result = app(Vite::class)->fonts()->toHtml();

        $this->assertStringContainsString('nonce="random-string-with-length:40"', $result);
        $this->assertStringContainsString('<style nonce="random-string-with-length:40">', $result);
        $this->assertStringContainsString('<link rel="preload" as="font" href="https://example.com/build/assets/inter-400.woff2" type="font/woff2" crossorigin="anonymous" nonce="random-string-with-length:40" />', $result);

        Str::createRandomStringsNormally();
    }

    public function testFontsRespectsUsePreloadTagAttributes(): void
    {
        $this->makeFontsManifest();
        $this->makeFontsCssFile('build', 'assets/fonts-abc123.css', "@font-face { font-family: 'Inter'; }");

        ViteFacade::usePreloadTagAttributes(fn ($src, $url, $chunk, $manifest) => [
            'data-turbo-track' => 'reload',
        ]);

        $result = app(Vite::class)->fonts()->toHtml();

        $this->assertStringContainsString('data-turbo-track="reload"', $result);
    }

    public function testFontsRespectsPreloadTagAttributesReturningFalse(): void
    {
        $this->makeFontsManifest();
        $this->makeFontsCssFile('build', 'assets/fonts-abc123.css', "@font-face { font-family: 'Inter'; }");

        ViteFacade::usePreloadTagAttributes(fn () => false);

        $result = app(Vite::class)->fonts()->toHtml();

        $this->assertStringNotContainsString('<link', $result);
        $this->assertStringContainsString('<style>', $result);
    }

    public function testFontsFiltersByAliasUsingManifestFamilyStyles(): void
    {
        $this->makeFontsManifest([
            'version' => 1,
            'style' => [
                'file' => 'assets/fonts-abc123.css',
                'familyStyles' => [
                    'sans' => "@font-face { font-family: \"Inter\"; src: url('inter.woff2'); }\n\n@font-face { font-family: \"Inter fallback\"; src: local(\"Arial\"); }",
                    'mono' => "@font-face { font-family: \"JetBrains Mono\"; src: url('jb.woff2'); }",
                ],
                'variables' => [
                    'sans' => '--font-sans: "Inter", "Inter fallback";',
                    'mono' => '--font-mono: "JetBrains Mono";',
                ],
            ],
            'preloads' => [
                [
                    'alias' => 'sans',
                    'family' => 'Inter',
                    'weight' => 400,
                    'style' => 'normal',
                    'file' => 'assets/inter-400.woff2',
                    'as' => 'font',
                    'type' => 'font/woff2',
                    'crossorigin' => 'anonymous',
                ],
                [
                    'alias' => 'mono',
                    'family' => 'JetBrains Mono',
                    'weight' => 400,
                    'style' => 'normal',
                    'file' => 'assets/jetbrains-400.woff2',
                    'as' => 'font',
                    'type' => 'font/woff2',
                    'crossorigin' => 'anonymous',
                ],
            ],
            'families' => [
                'sans' => ['family' => 'Inter', 'variable' => '--font-sans'],
                'mono' => ['family' => 'JetBrains Mono', 'variable' => '--font-mono'],
            ],
        ]);
        $this->makeFontsCssFile('build', 'assets/fonts-abc123.css', 'full-css-not-used-during-filtering');

        $result = app(Vite::class)->fonts(['sans'])->toHtml();

        $this->assertStringContainsString('inter-400.woff2', $result);
        $this->assertStringNotContainsString('jetbrains-400.woff2', $result);
        $this->assertStringContainsString('font-family: "Inter"', $result);
        $this->assertStringContainsString('font-family: "Inter fallback"', $result);
        $this->assertStringNotContainsString('font-family: "JetBrains Mono"', $result);
        $this->assertStringContainsString(':root {', $result);
        $this->assertStringContainsString('--font-sans:', $result);
        $this->assertStringNotContainsString('--font-mono:', $result);
        $this->assertStringNotContainsString('full-css-not-used-during-filtering', $result);
    }

    public function testFontsFilteredByAliasDoesNotThrowForMalformedPreloadOfOtherAlias(): void
    {
        $this->makeFontsManifest([
            'version' => 1,
            'style' => [
                'inline' => "@font-face { font-family: 'Inter'; }",
                'familyStyles' => [
                    'sans' => "@font-face { font-family: 'Inter'; }",
                ],
                'variables' => [],
            ],
            'preloads' => [
                [
                    'alias' => 'sans',
                    'family' => 'Inter',
                    'weight' => 400,
                    'style' => 'normal',
                    'file' => 'assets/inter-400.woff2',
                    'as' => 'font',
                    'type' => 'font/woff2',
                    'crossorigin' => 'anonymous',
                ],
                [
                    'alias' => 'broken',
                    'family' => 'Broken',
                    'as' => 'font',
                ],
            ],
            'families' => [
                'sans' => ['family' => 'Inter', 'variable' => '--font-sans'],
                'broken' => ['family' => 'Broken', 'variable' => '--font-broken'],
            ],
        ]);

        $result = app(Vite::class)->fonts(['sans'])->toHtml();

        $this->assertStringContainsString('inter-400.woff2', $result);
    }

    public function testFontsPreloadCallbackReceivesStableSourceIdentifier(): void
    {
        $this->makeFontsManifest();
        $this->makeFontsCssFile('build', 'assets/fonts-abc123.css', "@font-face { font-family: 'Inter'; }");

        $receivedSrc = null;

        ViteFacade::usePreloadTagAttributes(function ($src, $url, $chunk, $manifest) use (&$receivedSrc) {
            $receivedSrc = $src;

            return [];
        });

        app(Vite::class)->fonts();

        $this->assertSame('fonts', $receivedSrc);
    }

    public function testFontsAcceptsStringAlias(): void
    {
        $this->makeFontsManifest([
            'version' => 1,
            'style' => [
                'inline' => "@font-face { font-family: 'Inter'; }",
                'familyStyles' => [
                    'sans' => "@font-face { font-family: 'Inter'; }",
                ],
                'variables' => [],
            ],
            'preloads' => [],
            'families' => [
                'sans' => ['family' => 'Inter', 'variable' => '--font-sans'],
            ],
        ]);

        $result = app(Vite::class)->fonts('sans')->toHtml();

        $this->assertStringContainsString("font-family: 'Inter'", $result);
    }

    public function testFontsRecordsPreloadedAssets(): void
    {
        $this->makeFontsManifest();
        $this->makeFontsCssFile('build', 'assets/fonts-abc123.css', "@font-face { font-family: 'Inter'; }");

        app(Vite::class)->fonts();

        $preloaded = app(Vite::class)->preloadedAssets();

        $this->assertArrayHasKey('https://example.com/build/assets/inter-400.woff2', $preloaded);
    }

    public function testFontsPreloadedAssetsAreCoroutineIsolated(): void
    {
        $this->makeFontsManifest([
            'version' => 1,
            'style' => [
                'file' => 'assets/fonts-abc123.css',
                'familyStyles' => [
                    'sans' => "@font-face { font-family: 'Inter'; }",
                    'mono' => "@font-face { font-family: 'JetBrains Mono'; }",
                ],
                'variables' => [
                    'sans' => '--font-sans: "Inter";',
                    'mono' => '--font-mono: "JetBrains Mono";',
                ],
            ],
            'preloads' => [
                [
                    'alias' => 'sans',
                    'family' => 'Inter',
                    'weight' => 400,
                    'style' => 'normal',
                    'file' => 'assets/inter-400.woff2',
                    'as' => 'font',
                    'type' => 'font/woff2',
                    'crossorigin' => 'anonymous',
                ],
                [
                    'alias' => 'mono',
                    'family' => 'JetBrains Mono',
                    'weight' => 400,
                    'style' => 'normal',
                    'file' => 'assets/jetbrains-400.woff2',
                    'as' => 'font',
                    'type' => 'font/woff2',
                    'crossorigin' => 'anonymous',
                ],
            ],
            'families' => [
                'sans' => ['family' => 'Inter', 'variable' => '--font-sans'],
                'mono' => ['family' => 'JetBrains Mono', 'variable' => '--font-mono'],
            ],
        ]);

        [$sansPreloadedAssets, $monoPreloadedAssets] = parallel([
            function () {
                app(Vite::class)->fonts(['sans']);
                usleep(5000);

                return app(Vite::class)->preloadedAssets();
            },
            function () {
                app(Vite::class)->fonts(['mono']);
                usleep(5000);

                return app(Vite::class)->preloadedAssets();
            },
        ]);

        $this->assertSame(['https://example.com/build/assets/inter-400.woff2'], array_keys($sansPreloadedAssets));
        $this->assertSame(['https://example.com/build/assets/jetbrains-400.woff2'], array_keys($monoPreloadedAssets));
    }

    public function testFontsDoesNotDuplicatePreloadedAssets(): void
    {
        $this->makeFontsManifest();
        $this->makeFontsCssFile('build', 'assets/fonts-abc123.css', "@font-face { font-family: 'Inter'; }");

        $vite = app(Vite::class);

        $first = $vite->fonts()->toHtml();
        $second = $vite->fonts()->toHtml();

        $this->assertSame(1, substr_count($first, '<link'));
        $this->assertSame(0, substr_count($second, '<link'));
    }

    public function testMalformedManifestThrowsException(): void
    {
        $buildPath = public_path('build');

        if (! file_exists($buildPath)) {
            mkdir($buildPath, 0755, true);
        }

        file_put_contents(public_path('build/fonts-manifest.json'), 'not-valid-json{');

        try {
            app(Vite::class)->fonts();

            self::fail('Expected the malformed font manifest to be rejected.');
        } catch (ViteException $exception) {
            $this->assertStringContainsString('not valid JSON', $exception->getMessage());
            $this->assertInstanceOf(JsonException::class, $exception->getPrevious());
        }

        $this->makeFontsManifest([
            'version' => 1,
            'style' => ['inline' => ''],
            'preloads' => [],
            'families' => [],
        ]);

        $this->assertSame('', app(Vite::class)->fonts()->toHtml());
    }

    public function testNonArrayManifestThrowsException(): void
    {
        $buildPath = public_path('build');
        mkdir($buildPath, 0755, true);
        file_put_contents($buildPath . '/fonts-manifest.json', 'null');

        $this->expectException(ViteException::class);
        $this->expectExceptionMessage('The font manifest at [' . $buildPath . '/fonts-manifest.json] is invalid.');

        app(Vite::class)->fonts();
    }

    public function testUnreadableManifestThrowsException(): void
    {
        $this->withUnreadableFontStream(function (string $path): void {
            $this->expectException(ViteException::class);
            $this->expectExceptionMessage('Unable to read the font manifest');

            (new ViteFonts)->manifest(true, 'build', 'fonts-manifest.json', $path . '/hot');
        });
    }

    public function testUnsupportedManifestVersionThrowsException(): void
    {
        $this->makeFontsManifest(['version' => 99, 'families' => []]);

        $this->expectException(ViteException::class);
        $this->expectExceptionMessage('Unsupported font manifest version [99]. Supported versions: 1.');

        app(Vite::class)->fonts();
    }

    public function testMissingManifestVersionThrowsException(): void
    {
        $this->makeFontsManifest(['style' => ['inline' => ''], 'families' => []]);

        $this->expectException(ViteException::class);
        $this->expectExceptionMessage('missing the [version] key');

        app(Vite::class)->fonts();
    }

    public function testMissingFamiliesKeyThrowsException(): void
    {
        $this->makeFontsManifest(['version' => 1]);

        $this->expectException(ViteException::class);
        $this->expectExceptionMessage('missing the [families] key');

        app(Vite::class)->fonts();
    }

    public function testMissingCssFileThrowsException(): void
    {
        $this->makeFontsManifest();

        $this->expectException(ViteException::class);
        $this->expectExceptionMessage('Unable to locate font CSS file');

        app(Vite::class)->fonts();
    }

    public function testUnreadableCssFileThrowsException(): void
    {
        $this->withUnreadableFontStream(function (string $path): void {
            app()->usePublicPath($path);

            $this->expectException(ViteException::class);
            $this->expectExceptionMessage('Unable to read font CSS file from manifest');

            (new ViteFonts)->resolveStyleContent(
                ['style' => ['file' => 'assets/fonts.css']],
                null,
                'build',
            );
        });
    }

    public function testUnknownRequestedAliasThrowsException(): void
    {
        $this->makeFontsManifest([
            'version' => 1,
            'style' => ['inline' => ''],
            'preloads' => [],
            'families' => [
                'sans' => ['family' => 'Inter', 'variable' => '--font-sans'],
            ],
        ]);

        $this->expectException(ViteException::class);
        $this->expectExceptionMessage('Font alias [display] is not defined in the font manifest. Available aliases: sans.');

        app(Vite::class)->fonts(['display']);
    }

    public function testMalformedPreloadEntryMissingAliasThrowsException(): void
    {
        $this->makeFontsManifest([
            'version' => 1,
            'style' => ['inline' => ''],
            'preloads' => [
                ['family' => 'Inter', 'file' => 'assets/font.woff2', 'as' => 'font'],
            ],
            'families' => [
                'sans' => ['family' => 'Inter', 'variable' => '--font-sans'],
            ],
        ]);

        $this->expectException(ViteException::class);
        $this->expectExceptionMessage('preload entry [0] is missing the [alias] key');

        app(Vite::class)->fonts();
    }

    public function testMalformedPreloadEntryMissingFileInBuildModeThrowsException(): void
    {
        $this->makeFontsManifest([
            'version' => 1,
            'style' => ['inline' => ''],
            'preloads' => [
                ['alias' => 'sans', 'family' => 'Inter', 'as' => 'font'],
            ],
            'families' => [
                'sans' => ['family' => 'Inter', 'variable' => '--font-sans'],
            ],
        ]);

        $this->expectException(ViteException::class);
        $this->expectExceptionMessage('preload entry [0] for alias [sans] is missing the [file] key');

        app(Vite::class)->fonts();
    }

    public function testMalformedPreloadEntryMissingUrlInHotModeThrowsException(): void
    {
        $this->makeHotFile();
        $this->makeHotFontsManifest([
            'version' => 1,
            'style' => ['inline' => ''],
            'preloads' => [
                ['alias' => 'sans', 'family' => 'Inter', 'as' => 'font'],
            ],
            'families' => [
                'sans' => ['family' => 'Inter', 'variable' => '--font-sans'],
            ],
        ]);

        $this->expectException(ViteException::class);
        $this->expectExceptionMessage('preload entry [0] for alias [sans] is missing the [url] key');

        app(Vite::class)->fonts();
    }

    public function testMultiplePreloadsRenderedForMultipleWeights(): void
    {
        $this->makeFontsManifest([
            'version' => 1,
            'style' => ['inline' => ''],
            'preloads' => [
                [
                    'alias' => 'sans',
                    'family' => 'Inter',
                    'weight' => 400,
                    'style' => 'normal',
                    'file' => 'assets/inter-400.woff2',
                    'as' => 'font',
                    'type' => 'font/woff2',
                    'crossorigin' => 'anonymous',
                ],
                [
                    'alias' => 'sans',
                    'family' => 'Inter',
                    'weight' => 700,
                    'style' => 'normal',
                    'file' => 'assets/inter-700.woff2',
                    'as' => 'font',
                    'type' => 'font/woff2',
                    'crossorigin' => 'anonymous',
                ],
            ],
            'families' => [
                'sans' => ['family' => 'Inter', 'variable' => '--font-sans'],
            ],
        ]);

        $result = app(Vite::class)->fonts()->toHtml();

        $this->assertSame(2, substr_count($result, '<link'));
        $this->assertStringContainsString('inter-400.woff2', $result);
        $this->assertStringContainsString('inter-700.woff2', $result);
    }

    public function testFontsRendersEachPreloadLinkOnItsOwnLine(): void
    {
        $this->makeFontsManifest([
            'version' => 1,
            'style' => ['inline' => ''],
            'preloads' => [
                [
                    'alias' => 'sans',
                    'family' => 'Inter',
                    'weight' => 400,
                    'style' => 'normal',
                    'file' => 'assets/inter-400.woff2',
                    'as' => 'font',
                    'type' => 'font/woff2',
                    'crossorigin' => 'anonymous',
                ],
                [
                    'alias' => 'sans',
                    'family' => 'Inter',
                    'weight' => 700,
                    'style' => 'normal',
                    'file' => 'assets/inter-700.woff2',
                    'as' => 'font',
                    'type' => 'font/woff2',
                    'crossorigin' => 'anonymous',
                ],
            ],
            'families' => [
                'sans' => ['family' => 'Inter', 'variable' => '--font-sans'],
            ],
        ]);

        $result = app(Vite::class)->fonts()->toHtml();

        $this->assertStringContainsString("/>\n<link", $result);
        $this->assertStringNotContainsString('/><link', $result);
    }

    public function testFontsRendersStyleTagOnItsOwnLines(): void
    {
        $this->makeFontsManifest();
        $this->makeFontsCssFile('build', 'assets/fonts-abc123.css', "@font-face { font-family: 'Inter'; }");

        $result = app(Vite::class)->fonts()->toHtml();

        $this->assertMatchesRegularExpression('/<style>\n@font-face \{ font-family: \'Inter\'; \}\n<\/style>/', $result);
    }

    public function testFontsPutsANewlineBetweenTheLastPreloadAndTheStyleBlock(): void
    {
        $this->makeFontsManifest();
        $this->makeFontsCssFile('build', 'assets/fonts-abc123.css', "@font-face { font-family: 'Inter'; }");

        $result = app(Vite::class)->fonts()->toHtml();

        $this->assertMatchesRegularExpression('/\/>\n<style>/', $result);
    }

    public function testFontsThrowsContractExceptionWhenStyleVariablesIsAString(): void
    {
        $this->makeFontsManifest([
            'version' => 1,
            'style' => [
                'file' => 'assets/fonts-abc123.css',
                'familyStyles' => [
                    'sans' => "@font-face { font-family: 'Inter'; }",
                ],
                'variables' => ":root {\n  --font-sans: \"Inter\";\n}",
            ],
            'preloads' => [],
            'families' => [
                'sans' => ['family' => 'Inter', 'variable' => '--font-sans'],
            ],
        ]);
        $this->makeFontsCssFile('build', 'assets/fonts-abc123.css', "@font-face { font-family: 'Inter'; }");

        $this->expectException(ViteException::class);
        $this->expectExceptionMessage('keyed by alias');

        app(Vite::class)->fonts(['sans']);
    }

    public function testFontsThrowsContractExceptionWhenStyleFamilyStylesIsAString(): void
    {
        $this->makeFontsManifest([
            'version' => 1,
            'style' => [
                'file' => 'assets/fonts-abc123.css',
                'familyStyles' => "@font-face { font-family: 'Inter'; }",
                'variables' => ['sans' => '--font-sans: "Inter";'],
            ],
            'preloads' => [],
            'families' => [
                'sans' => ['family' => 'Inter', 'variable' => '--font-sans'],
            ],
        ]);
        $this->makeFontsCssFile('build', 'assets/fonts-abc123.css', "@font-face { font-family: 'Inter'; }");

        $this->expectException(ViteException::class);
        $this->expectExceptionMessage('keyed by alias');

        app(Vite::class)->fonts(['sans']);
    }

    public function testFontsDoesNotCheckVariablesShapeWhenNoAliasFilterIsGiven(): void
    {
        $this->makeFontsManifest([
            'version' => 1,
            'style' => [
                'file' => 'assets/fonts-abc123.css',
                'familyStyles' => [
                    'sans' => "@font-face { font-family: 'Inter'; }",
                ],
                'variables' => 'legacy-string-payload',
            ],
            'preloads' => [],
            'families' => [
                'sans' => ['family' => 'Inter', 'variable' => '--font-sans'],
            ],
        ]);
        $this->makeFontsCssFile('build', 'assets/fonts-abc123.css', "@font-face { font-family: 'Inter'; }");

        $result = app(Vite::class)->fonts()->toHtml();

        $this->assertStringContainsString("@font-face { font-family: 'Inter'; }", $result);
    }

    public function testFontsOutputIsDeterministic(): void
    {
        $this->makeFontsManifest();
        $this->makeFontsCssFile('build', 'assets/fonts-abc123.css', "@font-face { font-family: 'Inter'; }");

        $first = app(Vite::class)->fonts()->toHtml();

        app(Vite::class)->flush();

        $second = app(Vite::class)->fonts()->toHtml();

        $this->assertSame($first, $second);
    }

    public function testFontsWithNoPreloadsStillRendersStyle(): void
    {
        $this->makeFontsManifest([
            'version' => 1,
            'style' => ['inline' => "@font-face { font-family: 'Inter'; }"],
            'preloads' => [],
            'families' => [
                'sans' => ['family' => 'Inter', 'variable' => '--font-sans'],
            ],
        ]);

        $result = app(Vite::class)->fonts()->toHtml();

        $this->assertStringNotContainsString('<link', $result);
        $this->assertStringContainsString("<style>\n@font-face { font-family: 'Inter'; }\n</style>", $result);
    }

    public function testFontsWithNoStyleStillRendersPreloads(): void
    {
        $this->makeFontsManifest([
            'version' => 1,
            'preloads' => [
                [
                    'alias' => 'sans',
                    'family' => 'Inter',
                    'weight' => 400,
                    'style' => 'normal',
                    'file' => 'assets/inter-400.woff2',
                    'as' => 'font',
                    'type' => 'font/woff2',
                    'crossorigin' => 'anonymous',
                ],
            ],
            'families' => [
                'sans' => ['family' => 'Inter', 'variable' => '--font-sans'],
            ],
        ]);

        $result = app(Vite::class)->fonts()->toHtml();

        $this->assertStringContainsString('<link', $result);
        $this->assertStringNotContainsString('<style', $result);
    }

    public function testFlushIsSafeWhenFontsWasNeverAccessed(): void
    {
        $vite = app(Vite::class);

        $vite->flush();

        $this->assertEmpty($vite->preloadedAssets());
    }

    public function testFontsFlushClearsPreloadedAssetsButPreservesConfiguration(): void
    {
        $this->makeFontsManifest(manifestFilename: 'custom-fonts-manifest.json');
        $this->makeFontsCssFile('build', 'assets/fonts-abc123.css', "@font-face { font-family: 'Inter'; }");

        $vite = app(Vite::class);
        $vite->useFontsManifestFilename('custom-fonts-manifest.json');
        $vite->fonts();

        $this->assertNotEmpty($vite->preloadedAssets());

        $vite->flush();

        $this->assertEmpty($vite->preloadedAssets());

        $result = $vite->fonts()->toHtml();
        $this->assertStringContainsString('<link', $result);
    }

    public function testHotManifestPathDerivesFromHotFile(): void
    {
        $customHotDir = $this->tempDir . '/custom-hot-dir';

        if (! file_exists($customHotDir)) {
            mkdir($customHotDir, 0755, true);
        }

        file_put_contents($customHotDir . '/hot', 'http://localhost:3000');

        $manifest = json_encode([
            'version' => 1,
            'style' => [
                'inline' => "@font-face { font-family: 'Inter'; }",
            ],
            'preloads' => [],
            'families' => [
                'sans' => ['family' => 'Inter', 'variable' => '--font-sans'],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        file_put_contents($customHotDir . '/fonts-manifest.dev.json', $manifest);

        ViteFacade::useHotFile($customHotDir . '/hot');

        $result = app(Vite::class)->fonts()->toHtml();

        $this->assertStringContainsString("font-family: 'Inter'", $result);
    }

    public function testHotManifestNotFoundWithCustomHotFileReturnsEmpty(): void
    {
        $customHotDir = $this->tempDir . '/custom-hot-dir';

        if (! file_exists($customHotDir)) {
            mkdir($customHotDir, 0755, true);
        }

        file_put_contents($customHotDir . '/hot', 'http://localhost:3000');

        ViteFacade::useHotFile($customHotDir . '/hot');

        $result = app(Vite::class)->fonts()->toHtml();

        $this->assertSame('', $result);
    }

    public function testFontsRendersUtilityClassInBuildMode(): void
    {
        $this->makeFontsManifest([
            'version' => 1,
            'style' => [
                'file' => 'assets/fonts-abc123.css',
                'familyStyles' => [
                    'sans' => "@font-face { font-family: \"Inter\"; src: url('inter.woff2'); }\n\n.font-sans {\n  font-family: var(--font-sans);\n}",
                ],
                'variables' => ['sans' => '--font-sans: "Inter";'],
            ],
            'preloads' => [
                ['alias' => 'sans', 'family' => 'Inter', 'weight' => 400, 'style' => 'normal', 'file' => 'assets/inter-400.woff2', 'as' => 'font', 'type' => 'font/woff2', 'crossorigin' => 'anonymous'],
            ],
            'families' => [
                'sans' => ['family' => 'Inter', 'variable' => '--font-sans'],
            ],
        ]);
        $this->makeFontsCssFile('build', 'assets/fonts-abc123.css', "@font-face { font-family: \"Inter\"; }\n\n.font-sans { font-family: var(--font-sans); }");

        $result = app(Vite::class)->fonts()->toHtml();

        $this->assertStringContainsString('.font-sans', $result);
        $this->assertStringContainsString('font-family: var(--font-sans)', $result);
    }

    public function testFontsRendersUtilityClassInHotMode(): void
    {
        $this->makeHotFile();
        $this->makeHotFontsManifest([
            'version' => 1,
            'style' => [
                'inline' => "@font-face { font-family: \"Inter\"; }\n\n.font-sans {\n  font-family: var(--font-sans);\n}",
                'familyStyles' => [
                    'sans' => "@font-face { font-family: \"Inter\"; }\n\n.font-sans {\n  font-family: var(--font-sans);\n}",
                ],
                'variables' => ['sans' => '--font-sans: "Inter";'],
            ],
            'preloads' => [
                ['alias' => 'sans', 'family' => 'Inter', 'weight' => 400, 'style' => 'normal', 'url' => 'http://localhost:3000/__laravel_vite_plugin__/fonts/inter.woff2', 'as' => 'font', 'type' => 'font/woff2', 'crossorigin' => 'anonymous'],
            ],
            'families' => [
                'sans' => ['family' => 'Inter', 'variable' => '--font-sans'],
            ],
        ]);

        $result = app(Vite::class)->fonts()->toHtml();

        $this->assertStringContainsString('.font-sans', $result);
        $this->assertStringContainsString('font-family: var(--font-sans)', $result);
    }

    public function testFontsFilteredByAliasIncludesUtilityClass(): void
    {
        $this->makeFontsManifest([
            'version' => 1,
            'style' => [
                'file' => 'assets/fonts-abc123.css',
                'familyStyles' => [
                    'sans' => "@font-face { font-family: \"Inter\"; }\n\n.font-sans {\n  font-family: var(--font-sans);\n}",
                    'heading' => "@font-face { font-family: \"Roboto\"; }\n\n.font-heading {\n  font-family: var(--font-heading);\n}",
                ],
                'variables' => [
                    'sans' => '--font-sans: "Inter";',
                    'heading' => '--font-heading: "Roboto";',
                ],
            ],
            'preloads' => [
                ['alias' => 'sans', 'family' => 'Inter', 'weight' => 400, 'style' => 'normal', 'file' => 'assets/inter-400.woff2', 'as' => 'font', 'type' => 'font/woff2', 'crossorigin' => 'anonymous'],
                ['alias' => 'heading', 'family' => 'Roboto', 'weight' => 400, 'style' => 'normal', 'file' => 'assets/roboto-400.woff2', 'as' => 'font', 'type' => 'font/woff2', 'crossorigin' => 'anonymous'],
            ],
            'families' => [
                'sans' => ['family' => 'Inter', 'variable' => '--font-sans'],
                'heading' => ['family' => 'Roboto', 'variable' => '--font-heading'],
            ],
        ]);
        $this->makeFontsCssFile('build', 'assets/fonts-abc123.css', 'full-css');

        $result = app(Vite::class)->fonts(['sans'])->toHtml();

        $this->assertStringContainsString('.font-sans', $result);
        $this->assertStringNotContainsString('.font-heading', $result);
    }

    public function testFontsFiltersByMultipleAliases(): void
    {
        $this->makeFontsManifest([
            'version' => 1,
            'style' => [
                'file' => 'assets/fonts-abc123.css',
                'familyStyles' => [
                    'sans' => '@font-face { font-family: "Inter"; }',
                    'mono' => '@font-face { font-family: "JetBrains Mono"; }',
                    'heading' => '@font-face { font-family: "Playfair Display"; }',
                ],
                'variables' => [
                    'sans' => '--font-sans: "Inter";',
                    'mono' => '--font-mono: "JetBrains Mono";',
                    'heading' => '--font-heading: "Playfair Display";',
                ],
            ],
            'preloads' => [
                ['alias' => 'sans', 'family' => 'Inter', 'weight' => 400, 'style' => 'normal', 'file' => 'assets/inter-400.woff2', 'as' => 'font', 'type' => 'font/woff2', 'crossorigin' => 'anonymous'],
                ['alias' => 'mono', 'family' => 'JetBrains Mono', 'weight' => 400, 'style' => 'normal', 'file' => 'assets/jb-400.woff2', 'as' => 'font', 'type' => 'font/woff2', 'crossorigin' => 'anonymous'],
                ['alias' => 'heading', 'family' => 'Playfair Display', 'weight' => 400, 'style' => 'normal', 'file' => 'assets/playfair-400.woff2', 'as' => 'font', 'type' => 'font/woff2', 'crossorigin' => 'anonymous'],
            ],
            'families' => [
                'sans' => ['family' => 'Inter', 'variable' => '--font-sans'],
                'mono' => ['family' => 'JetBrains Mono', 'variable' => '--font-mono'],
                'heading' => ['family' => 'Playfair Display', 'variable' => '--font-heading'],
            ],
        ]);
        $this->makeFontsCssFile('build', 'assets/fonts-abc123.css', 'full-css');

        $result = app(Vite::class)->fonts(['sans', 'mono'])->toHtml();

        $this->assertStringContainsString('inter-400.woff2', $result);
        $this->assertStringContainsString('jb-400.woff2', $result);
        $this->assertStringNotContainsString('playfair-400.woff2', $result);
        $this->assertStringContainsString('font-family: "Inter"', $result);
        $this->assertStringContainsString('font-family: "JetBrains Mono"', $result);
        $this->assertStringNotContainsString('Playfair Display', $result);
        $this->assertStringContainsString('--font-sans:', $result);
        $this->assertStringContainsString('--font-mono:', $result);
        $this->assertStringNotContainsString('--font-heading:', $result);
    }

    public function testFontsFiltersByAliasWithSingleLineVariables(): void
    {
        $this->makeFontsManifest([
            'version' => 1,
            'style' => [
                'file' => 'assets/fonts-abc123.css',
                'familyStyles' => [
                    'sans' => '@font-face { font-family: "Inter"; }',
                    'mono' => '@font-face { font-family: "JetBrains Mono"; }',
                ],
                'variables' => [
                    'sans' => '--font-sans: "Inter", "Inter fallback";',
                    'mono' => '--font-mono: "JetBrains Mono";',
                ],
            ],
            'preloads' => [
                ['alias' => 'sans', 'family' => 'Inter', 'weight' => 400, 'style' => 'normal', 'file' => 'assets/inter-400.woff2', 'as' => 'font', 'type' => 'font/woff2', 'crossorigin' => 'anonymous'],
                ['alias' => 'mono', 'family' => 'JetBrains Mono', 'weight' => 400, 'style' => 'normal', 'file' => 'assets/jb-400.woff2', 'as' => 'font', 'type' => 'font/woff2', 'crossorigin' => 'anonymous'],
            ],
            'families' => [
                'sans' => ['family' => 'Inter', 'variable' => '--font-sans'],
                'mono' => ['family' => 'JetBrains Mono', 'variable' => '--font-mono'],
            ],
        ]);
        $this->makeFontsCssFile('build', 'assets/fonts-abc123.css', 'full-css');

        $result = app(Vite::class)->fonts(['sans'])->toHtml();

        $this->assertStringContainsString('--font-sans:', $result);
        $this->assertStringNotContainsString('--font-mono:', $result);
    }

    public function testFontsDuplicateFamilyWithDifferentAliasesRenderIndependently(): void
    {
        $this->makeFontsManifest([
            'version' => 1,
            'style' => [
                'file' => 'assets/fonts-abc123.css',
                'familyStyles' => [
                    'sans' => '@font-face { font-family: "Inter"; font-weight: 400; }',
                    'heading' => '@font-face { font-family: "Inter"; font-weight: 700; }',
                ],
                'variables' => [
                    'sans' => '--font-sans: "Inter";',
                    'heading' => '--font-heading: "Inter";',
                ],
            ],
            'preloads' => [
                ['alias' => 'sans', 'family' => 'Inter', 'weight' => 400, 'style' => 'normal', 'file' => 'assets/inter-400.woff2', 'as' => 'font', 'type' => 'font/woff2', 'crossorigin' => 'anonymous'],
                ['alias' => 'heading', 'family' => 'Inter', 'weight' => 700, 'style' => 'normal', 'file' => 'assets/inter-700.woff2', 'as' => 'font', 'type' => 'font/woff2', 'crossorigin' => 'anonymous'],
            ],
            'families' => [
                'sans' => ['family' => 'Inter', 'variable' => '--font-sans'],
                'heading' => ['family' => 'Inter', 'variable' => '--font-heading'],
            ],
        ]);
        $this->makeFontsCssFile('build', 'assets/fonts-abc123.css', 'full-css');

        $result = app(Vite::class)->fonts(['sans'])->toHtml();

        $this->assertStringContainsString('inter-400.woff2', $result);
        $this->assertStringNotContainsString('inter-700.woff2', $result);
        $this->assertStringContainsString('font-weight: 400', $result);
        $this->assertStringNotContainsString('font-weight: 700', $result);
    }

    public function testFontsRendersUtilityClassWithCustomAlias(): void
    {
        $this->makeFontsManifest([
            'version' => 1,
            'style' => [
                'file' => 'assets/fonts-abc123.css',
                'familyStyles' => [
                    'sans' => "@font-face { font-family: \"Inter\"; }\n\n.font-sans {\n  font-family: var(--font-sans);\n}",
                ],
                'variables' => ['sans' => '--font-sans: "Inter";'],
            ],
            'preloads' => [
                ['alias' => 'sans', 'family' => 'Inter', 'weight' => 400, 'style' => 'normal', 'file' => 'assets/inter-400.woff2', 'as' => 'font', 'type' => 'font/woff2', 'crossorigin' => 'anonymous'],
            ],
            'families' => [
                'sans' => ['family' => 'Inter', 'variable' => '--font-sans'],
            ],
        ]);
        $this->makeFontsCssFile('build', 'assets/fonts-abc123.css', "@font-face { font-family: \"Inter\"; }\n\n.font-sans { font-family: var(--font-sans); }");

        $result = app(Vite::class)->fonts()->toHtml();

        $this->assertStringContainsString('.font-sans', $result);
        $this->assertStringContainsString('font-family: var(--font-sans)', $result);
    }

    public function testFontsCallsIsRunningHotOnceInHotMode(): void
    {
        $this->makeHotFile();
        $this->makeHotFontsManifest();

        $vite = new class extends Vite {
            public int $isRunningHotCalls = 0;

            public function isRunningHot(): bool
            {
                ++$this->isRunningHotCalls;

                return parent::isRunningHot();
            }
        };

        $result = $vite->fonts();

        $this->assertSame(1, $vite->isRunningHotCalls);
        $this->assertStringContainsString('<link', $result->toHtml());
    }

    public function testFilterVariablesSignatureIsNonNullable(): void
    {
        $method = new ReflectionMethod(ViteFonts::class, 'filterVariables');
        $aliasesParam = $method->getParameters()[1];

        $this->assertSame('aliases', $aliasesParam->getName());
        $this->assertFalse(
            $aliasesParam->allowsNull(),
            'filterVariables() must declare $aliases as a non-nullable array; the null branch was dead.'
        );

        $type = $aliasesParam->getType();
        $this->assertNotNull($type);
        $this->assertSame('array', (string) $type);
    }

    public function testFilterVariablesEmitsOnlyRequestedAlias(): void
    {
        $fonts = new ViteFonts;
        $method = new ReflectionMethod(ViteFonts::class, 'filterVariables');

        $result = $method->invoke($fonts, [
            'sans' => '--font-sans: "Inter";',
            'mono' => '--font-mono: "JetBrains Mono";',
        ], ['sans']);

        $this->assertStringContainsString('--font-sans:', $result);
        $this->assertStringNotContainsString('--font-mono:', $result);
    }

    public function testFilterVariablesPreservesAliasOrder(): void
    {
        $fonts = new ViteFonts;
        $method = new ReflectionMethod(ViteFonts::class, 'filterVariables');

        $result = $method->invoke($fonts, [
            'sans' => '--font-sans: "Inter";',
            'mono' => '--font-mono: "JetBrains Mono";',
            'heading' => '--font-heading: "Playfair";',
        ], ['heading', 'sans']);

        $headingPos = strpos($result, '--font-heading:');
        $sansPos = strpos($result, '--font-sans:');

        $this->assertNotFalse($headingPos);
        $this->assertNotFalse($sansPos);
        $this->assertLessThan($sansPos, $headingPos);
        $this->assertStringNotContainsString('--font-mono:', $result);
    }

    public function testFilterVariablesSkipsUnknownAlias(): void
    {
        $fonts = new ViteFonts;
        $method = new ReflectionMethod(ViteFonts::class, 'filterVariables');

        $result = $method->invoke($fonts, [
            'sans' => '--font-sans: "Inter";',
        ], ['sans', 'missing']);

        $this->assertStringContainsString('--font-sans:', $result);
        $this->assertStringNotContainsString('missing', $result);
    }

    public function testFilterVariablesEmptyAliasListProducesNoBlock(): void
    {
        $fonts = new ViteFonts;
        $method = new ReflectionMethod(ViteFonts::class, 'filterVariables');

        $result = $method->invoke($fonts, [
            'sans' => '--font-sans: "Inter";',
        ], []);

        $this->assertSame('', $result);
    }

    public function testFontsCallsIsRunningHotOnceInBuildMode(): void
    {
        $this->makeFontsManifest();
        $this->makeFontsCssFile('build', 'assets/fonts-abc123.css', "@font-face { font-family: 'Inter'; }");

        $vite = new class extends Vite {
            public int $isRunningHotCalls = 0;

            public function isRunningHot(): bool
            {
                ++$this->isRunningHotCalls;

                return parent::isRunningHot();
            }
        };

        $result = $vite->fonts();

        $this->assertSame(1, $vite->isRunningHotCalls);
        $this->assertStringContainsString('<link', $result->toHtml());
    }

    protected function defaultManifest(): array
    {
        return [
            'version' => 1,
            'style' => [
                'file' => 'assets/fonts-abc123.css',
                'familyStyles' => [
                    'sans' => "@font-face { font-family: 'Inter'; }",
                ],
                'variables' => ['sans' => '--font-sans: "Inter";'],
            ],
            'preloads' => [
                [
                    'alias' => 'sans',
                    'family' => 'Inter',
                    'weight' => 400,
                    'style' => 'normal',
                    'file' => 'assets/inter-400.woff2',
                    'as' => 'font',
                    'type' => 'font/woff2',
                    'crossorigin' => 'anonymous',
                ],
            ],
            'families' => [
                'sans' => ['family' => 'Inter', 'variable' => '--font-sans'],
            ],
        ];
    }

    protected function defaultHotManifest(): array
    {
        return [
            'version' => 1,
            'style' => [
                'inline' => "@font-face { font-family: 'Inter'; src: url('http://localhost:3000/fonts/inter.woff2'); }",
                'familyStyles' => [
                    'sans' => "@font-face { font-family: 'Inter'; src: url('http://localhost:3000/fonts/inter.woff2'); }",
                ],
                'variables' => ['sans' => '--font-sans: "Inter";'],
            ],
            'preloads' => [
                [
                    'alias' => 'sans',
                    'family' => 'Inter',
                    'weight' => 400,
                    'style' => 'normal',
                    'url' => 'http://localhost:3000/__laravel_vite_plugin__/fonts/inter.woff2',
                    'as' => 'font',
                    'type' => 'font/woff2',
                    'crossorigin' => 'anonymous',
                ],
            ],
            'families' => [
                'sans' => ['family' => 'Inter', 'variable' => '--font-sans'],
            ],
        ];
    }

    protected function makeFontsManifest(
        ?array $contents = null,
        string $buildDir = 'build',
        string $manifestFilename = 'fonts-manifest.json'
    ): void {
        $dir = public_path($buildDir);

        if (! file_exists($dir)) {
            mkdir($dir, 0755, true);
        }

        $manifest = json_encode($contents ?? $this->defaultManifest(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        file_put_contents(public_path("{$buildDir}/{$manifestFilename}"), $manifest);
    }

    protected function makeFontsCssFile(string $buildDir, string $file, string $content): void
    {
        $dir = public_path($buildDir . '/assets');

        if (! file_exists($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(public_path("{$buildDir}/{$file}"), $content);
    }

    protected function makeHotFile(?string $path = null): void
    {
        $path ??= public_path('hot');

        $dir = dirname($path);

        if (! file_exists($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, 'http://localhost:3000');
    }

    protected function makeHotFontsManifest(?array $contents = null, ?string $dir = null): void
    {
        $dir ??= $this->tempDir;

        if (! file_exists($dir)) {
            mkdir($dir, 0755, true);
        }

        $manifest = json_encode($contents ?? $this->defaultHotManifest(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        file_put_contents($dir . '/fonts-manifest.dev.json', $manifest);
    }

    protected function cleanFontsManifest(string $buildDir = 'build'): void
    {
        $cssFile = public_path("{$buildDir}/assets/fonts-abc123.css");

        if (file_exists($cssFile)) {
            unlink($cssFile);
        }

        $assetsDir = public_path("{$buildDir}/assets");

        if (is_dir($assetsDir) && count(glob("{$assetsDir}/*")) === 0) {
            rmdir($assetsDir);
        }

        $manifestFile = public_path("{$buildDir}/fonts-manifest.json");

        if (file_exists($manifestFile)) {
            unlink($manifestFile);
        }

        $dir = public_path($buildDir);

        if (is_dir($dir) && count(glob("{$dir}/*")) === 0) {
            rmdir($dir);
        }
    }

    protected function cleanHotFontsManifest(?string $dir = null): void
    {
        $dir ??= $this->tempDir;

        $path = $dir . '/fonts-manifest.dev.json';

        if (file_exists($path)) {
            unlink($path);
        }

        if ($dir !== $this->tempDir && is_dir($dir) && count(glob("{$dir}/*")) === 0) {
            rmdir($dir);
        }
    }

    protected function cleanHotFile(?string $path = null): void
    {
        $path ??= public_path('hot');

        if (file_exists($path)) {
            unlink($path);
        }

        $dir = dirname($path);

        if ($dir !== $this->tempDir && is_dir($dir) && count(glob("{$dir}/*")) === 0) {
            rmdir($dir);
        }
    }

    protected function withUnreadableFontStream(callable $callback): mixed
    {
        $scheme = 'hypervel-vite-font-unreadable';

        $this->assertTrue(stream_wrapper_register($scheme, ViteFontUnreadableStreamWrapper::class));

        try {
            return $callback($scheme . '://file');
        } finally {
            stream_wrapper_unregister($scheme);
        }
    }
}

class ViteFontUnreadableStreamWrapper
{
    public mixed $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return false;
    }

    public function url_stat(string $path, int $flags): array
    {
        return [
            2 => 0100444,
            7 => 1,
            'mode' => 0100444,
            'size' => 1,
        ];
    }
}
