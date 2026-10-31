<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications;

use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Bus\Dispatcher as BusDispatcher;
use Hypervel\Foundation\Application;
use Hypervel\Notifications\ChannelManager;
use Hypervel\Notifications\Notification;
use Hypervel\Tests\Notifications\Fixtures\NotificationStub;
use Hypervel\Tests\TestCase;
use Hypervel\Translation\ArrayLoader;
use Hypervel\Translation\Translator;
use Mockery as m;

use function Hypervel\Coroutine\parallel;

class CoroutineIsolationTest extends TestCase
{
    public function testManagerOverridesAreIsolatedBetweenCoroutines(): void
    {
        $application = new Application;
        $application->instance('config', new ConfigRepository([]));
        $application->boot();
        $manager = new ChannelManager($application);

        $results = parallel([
            function () use ($manager): array {
                $manager->deliverVia('mail-a');
                $manager->locale('en');
                usleep(5000);

                return [$manager->deliversVia(), $manager->getLocale()];
            },
            function () use ($manager): array {
                $manager->deliverVia('mail-b');
                $manager->locale('fr');
                usleep(5000);

                return [$manager->deliversVia(), $manager->getLocale()];
            },
        ]);

        $this->assertSame([
            ['mail-a', 'en'],
            ['mail-b', 'fr'],
        ], $results);

        $freshContext = parallel([
            fn (): array => [$manager->deliversVia(), $manager->getLocale()],
        ]);

        $this->assertSame([['mail', null]], $freshContext);
    }

    public function testConcurrentSendsConsumeOnlyTheirOwnLocaleOverride(): void
    {
        $application = new Application;
        $application->instance('config', new ConfigRepository([]));
        $application->instance(BusDispatcher::class, m::mock(BusDispatcher::class));
        $translator = new Translator(new ArrayLoader, 'en');
        $application->instance('translator', $translator);
        $manager = new ChannelManager($application);
        $manager->locale('fr');
        $manager->extend('mail', fn () => new class($translator) {
            /**
             * Create a channel with the shared translator.
             */
            public function __construct(private Translator $translator)
            {
            }

            /**
             * Record the locale after another send can run.
             */
            public function send(object $notifiable, Notification $notification): void
            {
                usleep(1000);
                $notifiable->locales[] = $this->translator->getLocale();
            }
        });
        $application->boot();

        $send = function (string $locale) use ($manager): array {
            $first = (object) ['locales' => []];
            $second = (object) ['locales' => []];

            $manager->locale($locale)->send([$first, $second], new NotificationStub);
            $manager->send([$first, $second], new NotificationStub);

            return [$first->locales, $second->locales];
        };

        $this->assertSame([
            [['en', 'fr'], ['en', 'fr']],
            [['es', 'fr'], ['es', 'fr']],
        ], parallel([
            fn (): array => $send('en'),
            fn (): array => $send('es'),
        ]));
    }

    public function testConfigurationBeforeBootBecomesTheWorkerBaseline(): void
    {
        $application = new Application;
        $application->instance('config', new ConfigRepository([]));
        $manager = new ChannelManager($application);

        $manager->deliverVia('database');
        $manager->locale('fr');
        $application->boot();

        $results = parallel([
            fn (): array => [$manager->deliversVia(), $manager->getLocale()],
            fn (): array => [$manager->deliversVia(), $manager->getLocale()],
        ]);

        $this->assertSame([
            ['database', 'fr'],
            ['database', 'fr'],
        ], $results);
    }

    public function testStandaloneConfigurationUpdatesTheBaseline(): void
    {
        $container = new Container;
        $container->instance('config', new ConfigRepository([]));
        $manager = new ChannelManager($container);

        $manager->deliverVia('database');
        $manager->locale('fr');

        $this->assertSame('database', $manager->deliversVia());
        $this->assertSame('fr', $manager->getLocale());
    }
}
