<?php

declare(strict_types=1);

namespace Hypervel\Tests\Translation;

use Hypervel\Context\CoroutineContext;
use Hypervel\Tests\TestCase;
use Hypervel\Translation\ArrayLoader;
use Hypervel\Translation\MissingTranslationGroups;
use Hypervel\Translation\Translator;

use function Hypervel\Coroutine\parallel;
use function Hypervel\Coroutine\wait;

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
        $translator->setBaseLocale('es');

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
        $this->assertSame('es', $translator->getLocale());
    }

    public function testMissingGroupsAreRecheckedInLaterExecutions(): void
    {
        $loader = new MutableTranslationLoader;
        $translator = new Translator($loader, 'en');

        $this->assertSame(
            'messages.value',
            wait(fn (): string => $translator->string('messages.value'))
        );

        $loader->messages = ['value' => 'translated'];

        $this->assertSame(
            'translated',
            wait(fn (): string => $translator->string('messages.value'))
        );
        $this->assertSame(2, $loader->messageLoadCount);
    }

    public function testPositiveGroupsRemainCachedAcrossExecutions(): void
    {
        $loader = new MutableTranslationLoader;
        $loader->messages = ['value' => 'translated'];
        $translator = new Translator($loader, 'en');

        $this->assertSame(
            'translated',
            wait(fn (): string => $translator->string('messages.value'))
        );
        $this->assertSame(
            'translated',
            wait(fn (): string => $translator->string('messages.value'))
        );
        $this->assertSame(1, $loader->messageLoadCount);
    }

    public function testTranslatorInstancesDoNotShareMissingGroups(): void
    {
        $loader = new MutableTranslationLoader;
        $first = new Translator($loader, 'en');
        $second = new Translator($loader, 'en');

        $this->assertSame('messages.value', $first->get('messages.value'));
        $this->assertSame('messages.value', $second->get('messages.value'));
        $this->assertSame(2, $loader->messageLoadCount);
    }

    public function testCopiedMissingGroupsAreIndependent(): void
    {
        $contextKey = '__translation.test_missing_groups';
        $missingGroups = new MissingTranslationGroups;
        $missingGroups->mark('*', 'messages', 'en');
        CoroutineContext::set($contextKey, $missingGroups);

        [$isIndependent, $wasPresent, $wasForgotten] = wait(
            function () use ($contextKey, $missingGroups): array {
                /** @var MissingTranslationGroups $copy */
                $copy = CoroutineContext::get($contextKey);
                $wasPresent = $copy->has('*', 'messages', 'en');
                $copy->forget('*', 'messages', 'en');

                return [$copy !== $missingGroups, $wasPresent, ! $copy->has('*', 'messages', 'en')];
            },
            copyContext: [$contextKey]
        );

        $this->assertTrue($isIndependent);
        $this->assertTrue($wasPresent);
        $this->assertTrue($wasForgotten);
        $this->assertTrue($missingGroups->has('*', 'messages', 'en'));
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

class MutableTranslationLoader extends ArrayLoader
{
    public array $messages = [];

    public int $messageLoadCount = 0;

    public function load(string $locale, string $group, ?string $namespace = null): array
    {
        if ($group === 'messages') {
            ++$this->messageLoadCount;

            return $this->messages;
        }

        return parent::load($locale, $group, $namespace);
    }
}
