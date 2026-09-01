<?php

declare(strict_types=1);

namespace Hypervel\Tests\Container;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Response;
use Hypervel\Pipeline\Pipeline;
use Hypervel\Support\Carbon;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Collection;
use Hypervel\Support\Fluent;
use Hypervel\Support\LazyCollection;
use Hypervel\Support\MessageBag;
use Hypervel\Support\Stringable;
use Hypervel\Support\ViewErrorBag;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class FrameworkTransientTest extends TestCase
{
    #[DataProvider('transientClassProvider')]
    public function testFrameworkTransientClassesResolveFreshAndDoNotShareState(
        string $class,
        Closure $mutate,
        Closure $snapshot,
        mixed $changed,
        mixed $pristine,
    ): void {
        $container = new Container;
        $first = $container->make($class);
        $second = $container->make($class);

        $mutate($first);

        self::assertNotSame($first, $second);
        self::assertSame($changed, $snapshot($first));
        self::assertSame($pristine, $snapshot($second));
    }

    /**
     * Provide framework classes whose instances always own independent mutable state.
     */
    public static function transientClassProvider(): array
    {
        return [
            'collection' => [
                Collection::class,
                static function (Collection $collection): void {
                    $collection->push('changed');
                },
                static fn (Collection $collection): array => $collection->all(),
                ['changed'],
                [],
            ],
            'lazy collection' => [
                LazyCollection::class,
                static function (LazyCollection $collection): void {
                    $collection->source = ['changed'];
                },
                static fn (LazyCollection $collection): array => $collection->all(),
                ['changed'],
                [],
            ],
            'fluent' => [
                Fluent::class,
                static function (Fluent $fluent): void {
                    $fluent->set('changed', true);
                },
                static fn (Fluent $fluent): array => $fluent->toArray(),
                ['changed' => true],
                [],
            ],
            'message bag' => [
                MessageBag::class,
                static function (MessageBag $messageBag): void {
                    $messageBag->add('field', 'changed');
                },
                static fn (MessageBag $messageBag): array => $messageBag->getMessages(),
                ['field' => ['changed']],
                [],
            ],
            'view error bag' => [
                ViewErrorBag::class,
                static function (ViewErrorBag $viewErrorBag): void {
                    $viewErrorBag->put('default', new MessageBag(['field' => ['changed']]));
                },
                static fn (ViewErrorBag $viewErrorBag): array => array_keys($viewErrorBag->getBags()),
                ['default'],
                [],
            ],
            'stringable' => [
                Stringable::class,
                static function (Stringable $stringable): void {
                    $stringable[0] = 'x';
                },
                static fn (Stringable $stringable): string => (string) $stringable,
                'x',
                '',
            ],
            'pipeline' => [
                Pipeline::class,
                static function (Pipeline $pipeline): void {
                    $pipeline->send('changed');
                },
                static fn (Pipeline $pipeline): mixed => $pipeline->thenReturn(),
                'changed',
                null,
            ],
            'response' => [
                Response::class,
                static function (Response $response): void {
                    $response->setContent('changed');
                },
                static fn (Response $response): string|false => $response->getContent(),
                'changed',
                '',
            ],
            'json response' => [
                JsonResponse::class,
                static function (JsonResponse $response): void {
                    $response->setData(['changed' => true]);
                },
                static fn (JsonResponse $response): mixed => $response->getData(true),
                ['changed' => true],
                [],
            ],
        ];
    }

    public function testFrameworkDatesResolveFreshFromTheContainer(): void
    {
        Carbon::setTestNow('2026-09-01 12:00:00 UTC');

        $container = new Container;
        $firstMutable = $container->make(Carbon::class);
        $secondMutable = $container->make(Carbon::class);

        $firstMutable->addDay();

        self::assertNotSame($firstMutable, $secondMutable);
        self::assertSame('2026-09-02 12:00:00', $firstMutable->toDateTimeString());
        self::assertSame('2026-09-01 12:00:00', $secondMutable->toDateTimeString());

        CarbonImmutable::setTestNow('2026-09-03 12:00:00 UTC');

        $firstImmutable = $container->make(CarbonImmutable::class);

        CarbonImmutable::setTestNow('2026-09-04 12:00:00 UTC');

        $secondImmutable = $container->make(CarbonImmutable::class);

        self::assertNotSame($firstImmutable, $secondImmutable);
        self::assertSame('2026-09-03 12:00:00', $firstImmutable->toDateTimeString());
        self::assertSame('2026-09-04 12:00:00', $secondImmutable->toDateTimeString());
    }
}
