<?php

declare(strict_types=1);

namespace Hypervel\Tests\Translation;

use Hypervel\Tests\TestCase;
use Hypervel\Translation\ArrayLoader;
use Hypervel\Translation\Translator;

use function Hypervel\Coroutine\parallel;

class CoroutineIsolationTest extends TestCase
{
    public function testMissingKeyHandlingIsIsolatedPerCoroutine(): void
    {
        $translator = new Translator(new YieldingTranslationLoader, 'en');
        $missingKeys = [];

        $translator->handleMissingKeysUsing(function (string $key) use (&$missingKeys): string {
            $missingKeys[] = $key;

            return "missing:{$key}";
        });

        [$hasMissingKey, $translatedMissingKey] = parallel([
            fn (): bool => $translator->has('messages.first', 'en'),
            function () use ($translator): string {
                usleep(2500);

                return $translator->get('messages.second', [], 'fr');
            },
        ]);

        $this->assertFalse($hasMissingKey);
        $this->assertSame('missing:messages.second', $translatedMissingKey);
        $this->assertSame(['messages.second'], $missingKeys);
    }

    public function testLocaleMutationIsIsolatedBetweenConcurrentCoroutines(): void
    {
        $translator = new Translator(new ArrayLoader, 'en');

        [$firstLocale, $secondLocale] = parallel([
            function () use ($translator): string {
                $translator->setLocale('fr');
                usleep(5000);

                return $translator->getLocale();
            },
            function () use ($translator): string {
                $translator->setLocale('de');
                usleep(5000);

                return $translator->getLocale();
            },
        ]);

        $this->assertSame('fr', $firstLocale);
        $this->assertSame('de', $secondLocale);
        $this->assertSame('en', $translator->getLocale());
    }
}

class YieldingTranslationLoader extends ArrayLoader
{
    public function load(string $locale, string $group, ?string $namespace = null): array
    {
        if ($locale === 'en' && $group === '*') {
            usleep(5000);
        }

        return parent::load($locale, $group, $namespace);
    }
}
