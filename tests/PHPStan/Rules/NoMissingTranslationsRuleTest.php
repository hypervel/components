<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules;

use Hypervel\Container\Container;
use Hypervel\Filesystem\Filesystem;
use Hypervel\PHPStan\Collectors\UsedTranslationFacadeCollector;
use Hypervel\PHPStan\Collectors\UsedTranslationFunctionCollector;
use Hypervel\PHPStan\Collectors\UsedTranslationTranslatorCollector;
use Hypervel\PHPStan\Collectors\UsedTranslationViewCollector;
use Hypervel\PHPStan\Rules\NoMissingTranslationsRule;
use Hypervel\PHPStan\Support\ViewFileHelper;
use Hypervel\PHPStan\Support\ViewParser;
use Hypervel\Translation\FileLoader;
use Hypervel\Translation\Translator;
use LogicException;
use PhpParser\Node\Expr\CallLike;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_map;
use function dirname;
use function lang_path;
use function view;

/** @extends RuleTestCase<NoMissingTranslationsRule> */
class NoMissingTranslationsRuleTest extends RuleTestCase
{
    /** @var string[] */
    private array $translationDirectories = [];

    private ?Translator $translator = null;

    protected function getRule(): Rule
    {
        $viewParser = new ViewParser($this->getContainer()->getService('currentPhpVersionSimpleDirectParser'));
        $viewFileHelper = new ViewFileHelper([], $this->getFileHelper());

        $filesystem = new Filesystem;
        $rule = new NoMissingTranslationsRule(new UsedTranslationViewCollector($viewParser, $viewFileHelper, $filesystem), $viewFileHelper, $filesystem, $this->translationDirectories);

        // A test's own translator replaces the application's without changing the shared application.
        if ($this->translator !== null) {
            $container = new Container;
            $container->instance('translator', $this->translator);
            $rule->setContainer($container);
        }

        return $rule;
    }

    /** @return array<Collector<CallLike, mixed>> */
    protected function getCollectors(): array
    {
        return [
            new UsedTranslationFunctionCollector($this->createReflectionProvider()),
            new UsedTranslationTranslatorCollector,
            new UsedTranslationFacadeCollector,
        ];
    }

    public function testRule(): void
    {
        // Only keys whose group exists are reported, so the fixture application has a
        // lang file for each group these keys use.
        /** @see ../application/resources/views/translations.blade.php */
        $errorsFromBladeFile = [
            ['Translation "lang.get" has not been found.', 26],
            ['Translation "lang.choice" has not been found.', 27],
            // Lines 28 and 29 call Lang::trans() and Lang::transChoice(), which the translator does not have.

            ['Translation "a.b" has not been found.', 31],
            ['Translation "a.b.c" has not been found.', 32],
            ['Translation "a_b.c-d" has not been found.', 33],
            ['Translation "a.b" has not been found.', 34],
            ['Translation "a.b!" has not been found.', 35],
            ['Translation "a.translate me!" has not been found.', 36],
            ['Translation "a.über~" has not been found.', 37],
            ['Translation "app.i\\\'m" has not been found.', 38],
            ['Translation "app.i\'m" has not been found.', 39],
            ['Translation "app.\"ok\"" has not been found.', 40],
            ['Translation "app."ok"" has not been found.', 41],
            ['Translation "a.b" has not been found.', 42],

            ['Translation "directive.lang" has not been found.', 44],
            ['Translation "directive.choice" has not been found.', 45],

            ['Translation "surrounded.by.text" has not been found.', 47],
            ['Translation "surrounded.by.html.tags" has not been found.', 48],

            ['Translation "trans.uppercase" has not been found.', 50],
            // Line 51 calls another object's trans() method.
            // REMOVED: line 52's $t() is a JavaScript translation function, whose messages may not come from the lang files.
            ['Translation "double.underscore.helper.function" has not been found.', 53],

            ['Translation "lang.in.html.attribute" has not been found.', 55],
            ['Translation "lang.with.extra.paramater" has not been found.', 56],
            // Blade removes line 57's comment before compiling.
            ['Translation "lang.in.html.comment" has not been found.', 58],
            ['Translation "and.a.simple.translation.afterwards" has not been found.', 59],
            // Blade leaves line 60's test@lang() as text, like an email address.

            ['Translation "lang.with.new' . "\n" . 'line.char" has not been found.', 71],
            ['Translation "lang.spanning' . "\n" . 'multiple.lines" has not been found.', 72],
            ['Translation "lang.with.a.back\slash.single.quotes" has not been found.', 74],
            ['Translation "lang.with.a.back\slash.double.quotes" has not been found.', 75],
            ['Translation "lang.with.a.hex.char" has not been found.', 76],
        ];

        $this->analyse([__DIR__ . '/data/Translation.php'], [
            ...$errorsFromBladeFile,
            [
                'Translation "messages.test" has not been found.',
                18,
            ],
            [
                'Translation "messages.test" has not been found.',
                19,
            ],
            [
                'Translation "messages.test" has not been found.',
                20,
            ],
            [
                'Translation "messages.test" has not been found.',
                25,
            ],
            [
                'Translation "messages.test" has not been found.',
                26,
            ],
            [
                'Translation "messages.test" has not been found.',
                31,
            ],
            [
                'Translation "messages.test" has not been found.',
                32,
            ],
            [
                'Translation "sub/lines.farewell" has not been found.',
                40,
            ],
        ]);
    }

    public function testTracksTranslationAndViewDirectories(): void
    {
        $tracked = [];
        $scope = $this->createStubForIntersectionOfInterfaces([Scope::class, DependencyTracker::class]);
        $scope->method('trackDirectoryDependency')->willReturnCallback(static function (string $directory, string $pattern) use (&$tracked): void {
            $tracked[] = [$directory, $pattern];
        });

        $this->getRule()->processNode(new CollectedDataNode([], false), $scope);

        $this->assertSame([
            [dirname(__DIR__, 3) . '/src/translation/lang', '*'],
            [lang_path(), '*'],
            ...array_map(static fn (string $path): array => [$path, '*.blade.php'], view()->getFinder()->getPaths()),
        ], $tracked);
    }

    public function testLookupArguments(): void
    {
        $this->translationDirectories = [__DIR__ . '/../Fixtures/Rules/lang'];

        // A custom directive replaces the built-in one, and its handler decides what its arguments mean.
        $container = Container::getInstance();
        $compiler = $container->make('blade.compiler');
        $customCompiler = clone $compiler;
        $customCompiler->directive('push', static fn (string $expression): string => '');
        $container->instance('blade.compiler', $customCompiler);

        /** @see ../application/resources/views/translation-lookups.blade.php */
        $errorsFromBladeFile = [
            ['Translation "lookups.missing" has not been found for locale "fr".', 1],
            ['Translation "lookups.present" has not been found for locale "fr".', 3],
            ['Translation "lookups.missing" has not been found for locale "fr".', 4],
            ['Translation "lookups.missing" has not been found.', 5],
            ['Translation "lookups.missing" has not been found.', 10],
            ['Translation "lookups.missing" has not been found.', 11],
            ['Translation "lookups.missing" has not been found.', 12],
            ['Translation "lookups.missing" has not been found.', 21],
            ['Translation "lookups.missing" has not been found.', 26],
            ['Translation "lookups.missing" has not been found.', 27],
            ['Translation "lookups.missing" has not been found.', 28],
            ['Translation "lookups.missing" has not been found.', 29],
            ['Translation "lookups.missing" has not been found.', 30],
            ['Translation "lookups.missing" has not been found.', 32],
            ['Translation "lookups.missing" has not been found.', 36],
            ['Translation "lookups.missing" has not been found.', 38],
            ['Translation "lookups.missing" has not been found.', 42],
        ];

        try {
            // The configured directories replace the application's, so none of its groups are known.
            $this->analyse([__DIR__ . '/../Fixtures/Rules/TranslationLookups.php'], [
                ...$errorsFromBladeFile,
                ['Translation "lookups.missing" has not been found.', 20],
                ['Translation "lookups.missing" has not been found for locale "fr".', 24],
                ['Translation "lookups.present" has not been found for locale "fr".', 26],
                ['Translation "lookups.missing" has not been found for locale "en".', 30],
                ['Translation "lookups.missing" has not been found for locale "fr".', 30],
                ['Translation "lookups.missing" has not been found.', 31],
                ['Translation "lookups.missing" has not been found for locale "fr".', 31],
                ['Translation "lookups.missing" has not been found for locale "fr".', 33],
                ['Translation "lookups.missing" has not been found for locale "fr".', 34],
                ['Translation "lookups.missing" has not been found for locale "fr".', 35],
                ['Translation "lookups.missing" has not been found for locale "fr".', 36],
                ['Translation "lookups.missing" has not been found for locale "fr".', 37],
                ['Translation "lookups.missing" has not been found for locale "fr".', 38],
                ['Translation "lookups.apples" has not been found for locale "fr".', 42],
            ]);
        } finally {
            $container->instance('blade.compiler', $compiler);
        }
    }

    public function testBootRegisteredTranslations(): void
    {
        $translator = new Translator(new FileLoader(new Filesystem, []), 'en');
        $translator->setFallback('en');
        $translator->addNamespace('package', __DIR__ . '/../Fixtures/Rules/lang');
        $translator->addLines(['added.line' => 'Added'], 'en');
        $translator->handleMissingKeysUsing(static fn (): never => throw new LogicException('The rule ran the missing-key callback.'));

        $this->translator = $translator;

        $this->analyse([__DIR__ . '/../Fixtures/Rules/BootRegisteredTranslations.php'], [
            ['Translation "package::lookups.missing" has not been found.', 15],
            ['Translation "added.missing" has not been found.', 17],
            ['Translation "added.missing" has not been found for locale "fr".', 18],
        ]);
    }

    public function testLocalesFromTheTranslatorsResolver(): void
    {
        $translator = new Translator(new FileLoader(new Filesystem, []), 'en');
        $translator->setFallback('en');
        $translator->addNamespace('package', __DIR__ . '/../Fixtures/Rules/lang');
        $translator->determineLocalesUsing(static fn (array $locales): array => [...$locales, 'es']);

        $this->translator = $translator;

        // The Spanish line equals its key, so only the resolver's extra locale shows that it exists.
        $this->analyse([__DIR__ . '/../Fixtures/Rules/ResolvedLocaleTranslations.php'], [
            ['Translation "package::lookups.missing" has not been found.', 15],
        ]);
    }

    public function testNamespacedTranslationFunctionIsNotTheHelper(): void
    {
        $translator = new Translator(new FileLoader(new Filesystem, []), 'en');
        $translator->addNamespace('package', __DIR__ . '/../Fixtures/Rules/lang');

        $this->translator = $translator;

        $this->analyse([__DIR__ . '/data/NamespacedTranslationFunction.php'], [
            ['Translation "package::lookups.missing" has not been found.', 21],
        ]);
    }

    #[DataProvider('customTranslators')]
    public function testCustomTranslatorsAreNotChecked(Translator $translator): void
    {
        $this->translator = $translator;

        $this->analyse([__DIR__ . '/data/Translation.php'], []);
    }

    /** @return iterable<string, array{Translator}> */
    public static function customTranslators(): iterable
    {
        $directory = __DIR__ . '/../application/lang';

        yield 'custom translator' => [new class(new FileLoader(new Filesystem, $directory), 'en') extends Translator {
        }];

        yield 'custom loader' => [new Translator(new class(new Filesystem, $directory) extends FileLoader {
        }, 'en')];
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [
            __DIR__ . '/phpstan-rules.neon',
        ];
    }
}
