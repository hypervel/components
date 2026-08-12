<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications;

use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Container\Container;
use Hypervel\Notifications\ChannelManager;
use Hypervel\Tests\TestCase;

use function Hypervel\Coroutine\parallel;

class CoroutineIsolationTest extends TestCase
{
    public function testManagerOverridesAreIsolatedBetweenCoroutines(): void
    {
        $container = new Container;
        $container->instance('config', new ConfigRepository([]));
        $manager = new ChannelManager($container);

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
}
