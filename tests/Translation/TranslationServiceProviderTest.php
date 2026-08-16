<?php

declare(strict_types=1);

namespace Hypervel\Tests\Translation;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Translation\Translator as TranslatorContract;
use Hypervel\Foundation\Application;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\TestCase;
use Hypervel\Translation\ArrayLoader;
use Hypervel\Translation\MessageSelector;
use Hypervel\Translation\TranslationServiceProvider;
use Hypervel\Translation\Translator;
use Mockery as m;

use function Hypervel\Coroutine\parallel;

class TranslationServiceProviderTest extends TestCase
{
    public function testReloadConfigurationUpdatesTheRetainedTranslator(): void
    {
        $application = new Application;
        $config = new Repository([
            'app' => [
                'locale' => 'en',
                'fallback_locale' => 'fr',
            ],
        ]);
        $loader = (new ArrayLoader)->addMessages('en', 'messages', [
            'file' => 'before refresh',
        ]);
        $application->instance('config', $config);
        $provider = new TranslationServiceProvider($application);
        $provider->register();
        $application->instance('translation.loader', $loader);

        $translator = $application->make('translator');
        $selector = new MessageSelector;
        $translator->setSelector($selector);
        $translator->handleMissingKeysUsing(static fn (string $key): string => "missing:{$key}");
        $translator->stringable(
            CarbonImmutable::class,
            static fn (CarbonImmutable $value): string => $value->format('Y')
        );
        $translator->addLines([
            'messages.registered' => 'registered',
            'messages.year' => 'Year :year',
        ], 'en');

        $this->assertSame('before refresh', $translator->get('messages.file'));

        $loader->addMessages('en', 'messages', [
            'file' => 'after refresh',
        ]);
        $config->set([
            'app.locale' => 'es',
            'app.fallback_locale' => 'it',
        ]);
        $translator->setLocale('en');

        $provider->reloadConfiguration();

        $this->assertSame($translator, $application->make(Translator::class));
        $this->assertSame($loader, $translator->getLoader());
        $this->assertSame($selector, $translator->getSelector());
        $this->assertSame('en', $translator->getLocale());
        $this->assertSame('it', $translator->getFallback());
        $this->assertSame('after refresh', $translator->get('messages.file'));
        $this->assertSame('registered', $translator->get('messages.registered'));
        $this->assertSame(
            'Year 2026',
            $translator->get('messages.year', ['year' => CarbonImmutable::create(2026)])
        );
        $this->assertSame('missing:messages.missing', $translator->get('messages.missing', [], 'en', false));

        [$baseLocale] = parallel([
            static fn (): string => $translator->getLocale(),
        ]);

        $this->assertSame('es', $baseLocale);
    }

    public function testReloadConfigurationDoesNotResolveAnUnusedTranslator(): void
    {
        $application = new Application;
        $application->instance('config', new Repository([
            'app' => [
                'locale' => 'en',
                'fallback_locale' => 'fr',
            ],
        ]));
        $provider = new TranslationServiceProvider($application);
        $provider->register();

        $provider->reloadConfiguration();

        $this->assertFalse($application->resolved('translator'));
    }

    public function testReloadConfigurationLeavesApplicationTranslatorReplacementAlone(): void
    {
        $application = new Application;
        $application->instance('config', new Repository([
            'app' => [
                'locale' => 'en',
                'fallback_locale' => 'fr',
            ],
        ]));
        $provider = new TranslationServiceProvider($application);
        $provider->register();
        $replacement = m::mock(TranslatorContract::class);
        $application->instance('translator', $replacement);

        $provider->reloadConfiguration();

        $this->assertSame($replacement, $application->make('translator'));
    }
}
