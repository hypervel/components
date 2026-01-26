<?php

declare(strict_types=1);

namespace Hypervel\Translation;

use Closure;
use Countable;
use Hyperf\Context\Context;
use Hypervel\Contracts\Translation\Loader;
use Hypervel\Contracts\Translation\Translator as TranslatorContract;
use Hypervel\Support\Arr;
use Hypervel\Support\NamespacedItemResolver;
use Hypervel\Support\Str;
use Hypervel\Support\Traits\Macroable;
use Hypervel\Support\Traits\ReflectsClosures;
use InvalidArgumentException;

use function Hypervel\Support\enum_value;

class Translator extends NamespacedItemResolver implements TranslatorContract
{
    use Macroable;
    use ReflectsClosures;

    /**
     * The fallback locale used by the translator.
     */
    protected ?string $fallback = '';

    /**
     * The array of loaded translation groups.
     */
    protected array $loaded = [];

    /**
     * The message selector.
     */
    protected ?MessageSelector $selector = null;

    /**
     * The callable that should be invoked to determine applicable locales.
     *
     * @var ?callable
     */
    protected $determineLocalesUsing;

    /**
     * The custom rendering callbacks for stringable objects.
     */
    protected array $stringableHandlers = [];

    /**
     * The callback that is responsible for handling missing translation keys.
     *
     * @var null|callable
     */
    protected $missingTranslationKeyCallback;

    /**
     * Indicates whether missing translation keys should be handled.
     */
    protected bool $handleMissingTranslationKeys = true;

    /**
     * Create a new translator instance.
     *
     * @param Loader $loader The loader implementation
     * @param string $locale the default locale being used by the translator
     */
    public function __construct(
        protected Loader $loader,
        protected string $locale
    ) {
        $this->setLocale($locale);
    }

    /**
     * Determine if a translation exists for a given locale.
     */
    public function hasForLocale(string $key, ?string $locale = null): bool
    {
        return $this->has($key, $locale, false);
    }

    /**
     * Determine if a translation exists.
     */
    public function has(string $key, ?string $locale = null, bool $fallback = true): bool
    {
        $locale = $locale ?: $this->getLocale();

        // We should temporarily disable the handling of missing translation keys
        // while performing the existence check. After the check, we will turn
        // the missing translation keys handling back to its original value.
        $handleMissingTranslationKeys = $this->handleMissingTranslationKeys;

        $this->handleMissingTranslationKeys = false;

        $line = $this->get($key, [], $locale, $fallback);

        $this->handleMissingTranslationKeys = $handleMissingTranslationKeys;

        // For JSON translations, the loaded files will contain the correct line.
        // Otherwise, we must assume we are handling typical translation file
        // and check if the returned line is not the same as the given key.
        if (! is_null($this->loaded['*']['*'][$locale][$key] ?? null)) {
            return true;
        }

        return $line !== $key;
    }

    /**
     * Get the translation for the given key.
     */
    public function get(string $key, array $replace = [], ?string $locale = null, bool $fallback = true): array|string
    {
        $locale = $locale ?: $this->getLocale();

        // For JSON translations, there is only one file per locale, so we will simply load
        // that file and then we will be ready to check the array for the key. These are
        // only one level deep so we do not need to do any fancy searching through it.
        $this->load('*', '*', $locale);

        $line = $this->loaded['*']['*'][$locale][$key] ?? null;

        // If we can't find a translation for the JSON key, we will attempt to translate it
        // using the typical translation file. This way developers can always just use a
        // helper such as __ instead of having to pick between trans or __ with views.
        if (! isset($line)) {
            [$namespace, $group, $item] = $this->parseKey($key);

            // Here we will get the locale that should be used for the language line. If one
            // was not passed, we will use the default locales which was given to us when
            // the translator was instantiated. Then, we can load the lines and return.
            $locales = $fallback ? $this->localeArray($locale) : [$locale];

            foreach ($locales as $languageLineLocale) {
                if (! is_null($line = $this->getLine(
                    $namespace,
                    $group,
                    $languageLineLocale,
                    $item,
                    $replace
                ))) {
                    return $line;
                }
            }

            $key = $this->handleMissingTranslationKey(
                $key,
                $replace,
                $locale,
                $fallback
            );
        }

        // If the line doesn't exist, we will return back the key which was requested as
        // that will be quick to spot in the UI if language keys are wrong or missing
        // from the application's language files. Otherwise we can return the line.
        return $this->makeReplacements($line ?: $key, $replace);
    }

    /**
     * Get a translation according to an integer value.
     */
    public function choice(string $key, array|Countable|float|int $number, array $replace = [], ?string $locale = null): string
    {
        $line = $this->get(
            $key,
            [],
            $locale = $this->localeForChoice($key, $locale)
        );

        // If the given "number" is actually an array or countable we will simply count the
        // number of elements in an instance. This allows developers to pass an array of
        // items without having to count it on their end first which gives bad syntax.
        if (is_countable($number)) {
            $number = count($number);
        }

        if (! isset($replace['count'])) {
            $replace['count'] = $number;
        }

        return $this->makeReplacements(
            $this->getSelector()->choose($line, $number, $locale),
            $replace
        );
    }

    /**
     * Get the proper locale for a choice operation.
     */
    protected function localeForChoice(string $key, ?string $locale): string
    {
        $locale = $locale ?: $this->getLocale();

        return $this->hasForLocale($key, $locale) ? $locale : $this->fallback;
    }

    /**
     * Retrieve a language line out the loaded array.
     */
    protected function getLine(string $namespace, string $group, string $locale, ?string $item, array $replace): array|string|null
    {
        $this->load($namespace, $group, $locale);

        $line = Arr::get($this->loaded[$namespace][$group][$locale], $item);

        if (is_string($line)) {
            return $this->makeReplacements($line, $replace);
        }
        if (is_array($line) && count($line) > 0) {
            array_walk_recursive($line, function (&$value, $key) use ($replace) {
                $value = $this->makeReplacements($value, $replace);
            });

            return $line;
        }

        return null;
    }

    /**
     * Make the place-holder replacements on a line.
     */
    protected function makeReplacements(string $line, array $replace): string
    {
        if (empty($replace)) {
            return $line;
        }

        $shouldReplace = [];

        foreach ($replace as $key => $value) {
            if ($value instanceof Closure) {
                $line = preg_replace_callback(
                    '/<' . $key . '>(.*?)<\/' . $key . '>/',
                    fn ($args) => $value($args[1]),
                    $line
                );

                continue;
            }

            if (is_object($value)) {
                $value = isset($this->stringableHandlers[get_class($value)])
                    ? call_user_func($this->stringableHandlers[get_class($value)], $value)
                    : enum_value($value);
            }

            $key = (string) $key;
            $value = (string) ($value ?? '');

            $shouldReplace[':' . Str::ucfirst($key)] = Str::ucfirst($value);
            $shouldReplace[':' . Str::upper($key)] = Str::upper($value);
            $shouldReplace[':' . $key] = $value;
        }

        return strtr($line, $shouldReplace);
    }

    /**
     * Add translation lines to the given locale.
     */
    public function addLines(array $lines, string $locale, string $namespace = '*'): void
    {
        foreach ($lines as $key => $value) {
            [$group, $item] = explode('.', $key, 2);

            Arr::set($this->loaded, "{$namespace}.{$group}.{$locale}.{$item}", $value);
        }
    }

    /**
     * Load the specified language group.
     */
    public function load(string $namespace, string $group, string $locale): void
    {
        if ($this->isLoaded($namespace, $group, $locale)) {
            return;
        }

        // The loader is responsible for returning the array of language lines for the
        // given namespace, group, and locale. We'll set the lines in this array of
        // lines that have already been loaded so that we can easily access them.
        $lines = $this->loader->load($locale, $group, $namespace);

        $this->loaded[$namespace][$group][$locale] = $lines;
    }

    /**
     * Determine if the given group has been loaded.
     */
    protected function isLoaded(string $namespace, string $group, string $locale): bool
    {
        return isset($this->loaded[$namespace][$group][$locale]);
    }

    /**
     * Handle a missing translation key.
     */
    protected function handleMissingTranslationKey(string $key, array $replace, ?string $locale, bool $fallback): string
    {
        if (! $this->handleMissingTranslationKeys
            || ! isset($this->missingTranslationKeyCallback)
        ) {
            return $key;
        }

        // Prevent infinite loops...
        $this->handleMissingTranslationKeys = false;

        $key = call_user_func(
            $this->missingTranslationKeyCallback,
            $key,
            $replace,
            $locale,
            $fallback
        ) ?? $key;

        $this->handleMissingTranslationKeys = true;

        return $key;
    }

    /**
     * Register a callback that is responsible for handling missing translation keys.
     */
    public function handleMissingKeysUsing(?callable $callback): static
    {
        $this->missingTranslationKeyCallback = $callback;

        return $this;
    }

    /**
     * Add a new namespace to the loader.
     */
    public function addNamespace(string $namespace, string $hint): void
    {
        $this->loader->addNamespace($namespace, $hint);
    }

    /**
     * Add a new path to the loader.
     */
    public function addPath(string $path): void
    {
        $this->loader->addPath($path);
    }

    /**
     * Add a new JSON path to the loader.
     */
    public function addJsonPath(string $path): void
    {
        $this->loader->addJsonPath($path);
    }

    /**
     * Parse a key into namespace, group, and item.
     */
    public function parseKey(string $key): array
    {
        $segments = parent::parseKey($key);

        if (is_null($segments[0])) {
            $segments[0] = '*';
        }

        return $segments;
    }

    /**
     * Get the array of locales to be checked.
     */
    protected function localeArray(?string $locale): array
    {
        $locales = array_filter([$locale ?: $this->getLocale(), $this->fallback]);

        return call_user_func($this->determineLocalesUsing ?: fn () => $locales, $locales);
    }

    /**
     * Specify a callback that should be invoked to determined the applicable locale array.
     */
    public function determineLocalesUsing(callable $callback): void
    {
        $this->determineLocalesUsing = $callback;
    }

    /**
     * Get the message selector instance.
     */
    public function getSelector(): MessageSelector
    {
        if (! isset($this->selector)) {
            $this->selector = new MessageSelector();
        }

        return $this->selector;
    }

    /**
     * Set the message selector instance.
     */
    public function setSelector(MessageSelector $selector): void
    {
        $this->selector = $selector;
    }

    /**
     * Get the language line loader implementation.
     */
    public function getLoader(): Loader
    {
        return $this->loader;
    }

    /**
     * Get the default locale being used.
     */
    public function locale(): string
    {
        return $this->getLocale();
    }

    /**
     * Get the default locale being used.
     */
    public function getLocale(): string
    {
        return (string) (Context::get('__translator.locale') ?? $this->locale);
    }

    /**
     * Set the default locale.
     *
     * @throws InvalidArgumentException
     */
    public function setLocale(string $locale): void
    {
        if (Str::contains($locale, ['/', '\\'])) {
            throw new InvalidArgumentException('Invalid characters present in locale.');
        }

        Context::set('__translator.locale', $locale);
    }

    /**
     * Get the fallback locale being used.
     */
    public function getFallback(): string
    {
        return $this->fallback;
    }

    /**
     * Set the fallback locale being used.
     */
    public function setFallback(string $fallback): void
    {
        $this->fallback = $fallback;
    }

    /**
     * Set the loaded translation groups.
     */
    public function setLoaded(array $loaded): void
    {
        $this->loaded = $loaded;
    }

    /**
     * Add a handler to be executed in order to format a given class to a string during translation replacements.
     */
    public function stringable(callable|string $class, ?callable $handler = null): void
    {
        if ($class instanceof Closure) {
            [$class, $handler] = [
                $this->firstClosureParameterType($class),
                $class,
            ];
        }

        $this->stringableHandlers[$class] = $handler;
    }

    /**
     * Get the translation for a given key.
     */
    public function trans(string $key, array $replace = [], ?string $locale = null): array|string
    {
        return $this->get($key, $replace, $locale);
    }

    /**
     * Get a translation according to an integer value.
     *
     * @param array|Countable|int $number
     */
    public function transChoice(string $key, $number, array $replace = [], ?string $locale = null): string
    {
        return $this->choice($key, $number, $replace, $locale);
    }
}
