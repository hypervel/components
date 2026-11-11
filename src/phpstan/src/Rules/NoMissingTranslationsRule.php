<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules;

use Hypervel\Contracts\Translation\Translator as TranslatorContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\PHPStan\Collectors\UsedTranslationFacadeCollector;
use Hypervel\PHPStan\Collectors\UsedTranslationFunctionCollector;
use Hypervel\PHPStan\Collectors\UsedTranslationTranslatorCollector;
use Hypervel\PHPStan\Collectors\UsedTranslationViewCollector;
use Hypervel\PHPStan\Concerns\CollectsTranslationLookups;
use Hypervel\PHPStan\Concerns\HasContainer;
use Hypervel\PHPStan\Support\RuntimeInputs;
use Hypervel\PHPStan\Support\ViewFileHelper;
use Hypervel\Support\Arr;
use Hypervel\Translation\FileLoader;
use Hypervel\Translation\Translator;
use PhpParser\Node;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredParameter;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;

use function array_key_exists;
use function array_merge;
use function array_values;
use function is_array;
use function sprintf;

/**
 * Upstream compares each key with the keys in the lang files. This rule asks the booted translator,
 * so fallbacks, JSON, namespaces and boot-added lines count, and reports only keys whose group exists.
 *
 * @phpstan-import-type TranslationLookup from CollectsTranslationLookups
 *
 * @implements Rule<CollectedDataNode>
 */
#[Conditional(attribute: new AutowiredService, if: '%checkMissingTranslations%')]
class NoMissingTranslationsRule implements Rule
{
    use HasContainer;

    /**
     * Create a new rule instance.
     *
     * @param string[] $translationDirectories directories that replace the application's translation sources
     */
    public function __construct(
        private UsedTranslationViewCollector $usedTranslationViewCollector,
        private ViewFileHelper $viewFileHelper,
        private Filesystem $filesystem,
        private RuntimeInputs $runtimeInputs,
        #[AutowiredParameter]
        private array $translationDirectories,
    ) {
    }

    /**
     * Get the node type the rule inspects.
     */
    public function getNodeType(): string
    {
        return CollectedDataNode::class;
    }

    /**
     * Report collected translation lookups the translator cannot resolve.
     *
     * @param DependencyTracker&Scope $scope
     * @return RuleError[]
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $this->runtimeInputs->track($scope);

        $translator = $this->translator();
        $loader = $translator instanceof Translator ? $translator->getLoader() : null;

        // A subclassed or custom translator or loader can resolve lines its own way, such
        // as from an external service, so a missing line cannot be established.
        if (! $translator instanceof Translator
            || $translator::class !== Translator::class
            || ! $loader instanceof FileLoader
            || $loader::class !== FileLoader::class) {
            return [];
        }

        // The rule reads the translation files and the views, so the result cache can reuse its errors only while they stay the same.
        $this->runtimeInputs->trackLinkedDirectories($scope, [...$loader->paths(), ...$loader->jsonPaths(), ...array_values($loader->namespaces())]);
        $this->viewFileHelper->trackRootViewDirectories($scope);

        /** @var array<string, TranslationLookup[]>[] $collectors */
        $collectors = [
            $node->get(UsedTranslationFunctionCollector::class),
            $node->get(UsedTranslationTranslatorCollector::class),
            $node->get(UsedTranslationFacadeCollector::class),
            $this->usedTranslationViewCollector->getUsedTranslations(),
        ];

        $usedTranslations = [];

        foreach ($collectors as $collector) {
            foreach ($collector as $file => $translations) {
                if (! array_key_exists($file, $usedTranslations)) {
                    $usedTranslations[$file] = [];
                }

                $usedTranslations[$file] = array_merge($usedTranslations[$file], $translations);
            }
        }

        $errors = [];

        foreach ($usedTranslations as $file => $translations) {
            foreach ($translations as ['key' => $translation, 'line' => $line, 'locales' => $locales, 'fallback' => $fallback, 'choice' => $choice]) {
                foreach ($locales as $locale) {
                    if (! $this->isMissing($translator, $translation, $locale, $fallback, $choice)) {
                        continue;
                    }

                    $message = $locale === null || $locale === ''
                        ? sprintf('Translation "%s" has not been found.', $translation)
                        : sprintf('Translation "%s" has not been found for locale "%s".', $translation, $locale);

                    $errors[] = RuleErrorBuilder::message($message)
                        ->file($file)
                        ->line($line)
                        ->identifier('hypervel.missingTranslations')
                        ->build();
                }
            }
        }

        return $errors;
    }

    /**
     * Get the translator the lookups are checked against.
     */
    private function translator(): TranslatorContract
    {
        /** @var TranslatorContract $translator */
        $translator = $this->resolve('translator');

        if ($this->translationDirectories === []) {
            return $translator;
        }

        // The configured directories replace the application's translation sources,
        // as upstream's do, without changing the application's own translator.
        $replacement = new Translator(new FileLoader($this->filesystem, $this->translationDirectories), $translator->getLocale());
        $replacement->setFallback($translator->getFallback());

        return $replacement;
    }

    /**
     * Determine whether a structured key with a known group has no translation.
     *
     * The exact framework translator is checked before this runs, so its protected
     * locale resolution, including a determineLocalesUsing() callback, is the one the
     * lookup itself uses.
     */
    private function isMissing(Translator $translator, string $key, ?string $locale, bool $fallback, bool $choice): bool
    {
        $locale = $locale ?: $translator->getLocale();

        // choice() reads the fallback locale's line when the requested locale has none.
        if ($choice) {
            $locale = (fn (): string => $this->localeForChoice($key, $locale))->call($translator);
        }

        if ($translator->has($key, $locale, $fallback)) {
            return false;
        }

        [$namespace, $group, $item] = $translator->parseKey($key);
        $groupKey = $namespace === '*' ? $group : "{$namespace}::{$group}";
        $knownGroup = false;

        /** @var list<string> $locales */
        $locales = $fallback ? (fn (): array => $this->localeArray($locale))->call($translator) : [$locale];

        foreach ($locales as $groupLocale) {
            // has() skips the application's missing-key callback, which get() runs for a missing group.
            if (! $translator->has($groupKey, $groupLocale, false)) {
                continue;
            }

            $lines = $translator->get($groupKey, [], $groupLocale, false);

            if (! is_array($lines)) {
                continue;
            }

            $knownGroup = true;

            // has() compares the line with the key, so it misses a line equal to its key.
            if ($item !== null && Arr::get($lines, $item) === $key) {
                return false;
            }
        }

        return $knownGroup;
    }
}
