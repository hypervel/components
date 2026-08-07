<?php

declare(strict_types=1);

namespace Hypervel\Translation;

use Closure;
use Countable;
use Hypervel\Context\CoroutineContext;
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
     * Context key for the per-request locale override.
     */
    protected const LOCALE_CONTEXT_KEY = '__translation.locale';

    /**
     * Context key for suppressing missing-key callbacks in the current coroutine.
     */
    protected const MISSING_KEY_HANDLING_CONTEXT_KEY = '__translation.handle_missing_keys';

    /**
     * The fallback locale used by the translator.
     */
    protected string $fallback = '';

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
     *
     * @var array<class-string, callable>
     */
    protected array $stringableHandlers = [];

    /**
     * The callback that is responsible for handling missing translation keys.
     *
     * @var null|callable
     */
    protected $missingTranslationKeyCallback;

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

        $line = $this->withoutMissingKeyHandling(
            fn () => $this->get($key, [], $locale, $fallback)
        );

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
        return $this->makeReplacements($line ?? $key, $replace);
    }

    /**
     * Get the specified string translation value.
     *
     * @throws InvalidArgumentException
     */
    public function string(string $key, array $replace = [], ?string $locale = null, bool $fallback = true): string
    {
        $value = $this->get($key, $replace, $locale, $fallback);

        if (! is_string($value)) {
            throw new InvalidArgumentException(
                sprintf('Translation value for key [%s] must be a string, %s given.', $key, gettype($value))
            );
        }

        return $value;
    }

    /**
     * Get the specified array translation value.
     *
     * @return array<array-key, mixed>
     *
     * @throws InvalidArgumentException
     */
    public function array(string $key, array $replace = [], ?string $locale = null, bool $fallback = true): array
    {
        $value = $this->get($key, $replace, $locale, $fallback);

        if (! is_array($value)) {
            throw new InvalidArgumentException(
                sprintf('Translation value for key [%s] must be an array, %s given.', $key, gettype($value))
            );
        }

        return $value;
    }

    /**
     * Get a translation according to an integer value.
     */
    public function choice(string $key, array|Countable|float|int $number, array $replace = [], ?string $locale = null): string
    {
        $line = $this->string(
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

        if (is_string($line) || (is_array($line) && $line !== [])) {
            return $this->makeReplacements($line, $replace);
        }

        return null;
    }

    /**
     * Make the place-holder replacements on a line.
     *
     * @return ($line is array ? array : string)
     */
    protected function makeReplacements(array|string $line, array $replace): array|string
    {
        if (empty($replace)) {
            return $line;
        }

        if (is_array($line)) {
            foreach ($line as $key => $value) {
                if (is_array($value) || is_string($value)) {
                    $line[$key] = $this->makeReplacements($value, $replace);
                }
            }

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
     *
     * Boot-only. The lines are stored on the shared Translator instance and
     * affect every subsequent lookup in the worker.
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
        if (! $this->shouldHandleMissingTranslationKeys()
            || ! isset($this->missingTranslationKeyCallback)
        ) {
            return $key;
        }

        return $this->withoutMissingKeyHandling(function () use ($key, $replace, $locale, $fallback) {
            return call_user_func(
                $this->missingTranslationKeyCallback,
                $key,
                $replace,
                $locale,
                $fallback
            ) ?? $key;
        });
    }

    /**
     * Determine if missing translation keys should be handled in this coroutine.
     */
    protected function shouldHandleMissingTranslationKeys(): bool
    {
        return (bool) CoroutineContext::get(self::MISSING_KEY_HANDLING_CONTEXT_KEY, true);
    }

    /**
     * Run the callback without firing missing-key callbacks in this coroutine.
     */
    protected function withoutMissingKeyHandling(callable $callback): mixed
    {
        $previous = CoroutineContext::get(self::MISSING_KEY_HANDLING_CONTEXT_KEY, true);

        CoroutineContext::set(self::MISSING_KEY_HANDLING_CONTEXT_KEY, false);

        try {
            return $callback();
        } finally {
            CoroutineContext::set(self::MISSING_KEY_HANDLING_CONTEXT_KEY, $previous);
        }
    }

    /**
     * Register a callback that is responsible for handling missing translation keys.
     *
     * Boot-only. The callback is stored on the shared Translator instance and
     * affects every subsequent missing-key lookup in the worker.
     */
    public function handleMissingKeysUsing(?callable $callback): static
    {
        $this->missingTranslationKeyCallback = $callback;

        return $this;
    }

    /**
     * Add a new namespace to the loader.
     *
     * Boot-only. The namespace is added to the shared loader and affects every
     * subsequent translation lookup in the worker.
     */
    public function addNamespace(string $namespace, string $hint): void
    {
        $this->loader->addNamespace($namespace, $hint);
    }

    /**
     * Add a new path to the loader.
     *
     * Boot-only. The path is added to the shared loader and affects every
     * subsequent translation lookup in the worker.
     */
    public function addPath(string $path): void
    {
        $this->loader->addPath($path);
    }

    /**
     * Add a new JSON path to the loader.
     *
     * Boot-only. The path is added to the shared loader and affects every
     * subsequent translation lookup in the worker.
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

        $determined = call_user_func($this->determineLocalesUsing ?: fn () => $locales, $locales);

        return array_values(array_unique($determined));
    }

    /**
     * Specify a callback that should be invoked to determine the applicable locale array.
     *
     * Boot-only. The callback is stored on the shared Translator instance and
     * affects every subsequent locale resolution in the worker.
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
            $this->selector = new MessageSelector;
        }

        return $this->selector;
    }

    /**
     * Set the message selector instance.
     *
     * Boot-only. The selector is stored on the shared Translator instance and
     * affects every subsequent choice() call in the worker.
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
     * Get the current locale being used.
     */
    public function locale(): string
    {
        return $this->getLocale();
    }

    /**
     * Get the current locale being used.
     */
    public function getLocale(): string
    {
        return (string) (CoroutineContext::get(self::LOCALE_CONTEXT_KEY) ?? $this->locale);
    }

    /**
     * Set the current locale.
     *
     * @throws InvalidArgumentException
     */
    public function setLocale(string $locale): void
    {
        // Mirrors the trust-boundary check in FileLoader::load(); keep both predicates identical.
        if (Str::contains($locale, ['/', '\\']) || $locale === '.' || $locale === '..') {
            throw new InvalidArgumentException('Invalid characters present in locale.');
        }

        CoroutineContext::set(self::LOCALE_CONTEXT_KEY, $locale);
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
     *
     * Boot-only. The fallback is shared by the worker's Translator instance and
     * affects every subsequent translation lookup in that worker. Use
     * setLocale() for a current-request locale override.
     */
    public function setFallback(string $fallback): void
    {
        $this->fallback = $fallback;
    }

    /**
     * Set the loaded translation groups.
     *
     * Boot-only. The groups are stored on the shared Translator instance and
     * affect every subsequent translation lookup in the worker.
     */
    public function setLoaded(array $loaded): void
    {
        $this->loaded = $loaded;
    }

    /**
     * Add a handler to be executed in order to format a given class to a string during translation replacements.
     *
     * Boot-only. The handler is stored on the shared Translator instance and
     * affects every subsequent replacement in the worker.
     *
     * @param class-string|Closure $class
     */
    public function stringable(Closure|string $class, ?callable $handler = null): void
    {
        if ($class instanceof Closure) {
            [$class, $handler] = [
                $this->firstClosureParameterType($class),
                $class,
            ];
        }

        if ($handler === null) {
            throw new InvalidArgumentException('A handler must be provided when registering a stringable class.');
        }

        $this->stringableHandlers[$class] = $handler;
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::flushMacros();
    }
}
