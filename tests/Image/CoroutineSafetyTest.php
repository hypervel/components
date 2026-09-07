<?php

declare(strict_types=1);

namespace Hypervel\Tests\Image;

use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Image\Driver;
use Hypervel\Contracts\Image\Transformation;
use Hypervel\Image\Image;
use Hypervel\Image\ImageManager;
use Hypervel\Image\ImagePipeline;
use Hypervel\Tests\TestCase;
use RuntimeException;
use Throwable;

use function Hypervel\Coroutine\parallel;

class CoroutineSafetyTest extends TestCase
{
    public function testClonedImagesResolveAYieldingSourceOnce(): void
    {
        $resolutionCalls = 0;
        $image = new Image(function () use (&$resolutionCalls): string {
            ++$resolutionCalls;
            usleep(5000);

            return 'shared image';
        });
        $first = $image->using('first');
        $second = $image->using('second');

        $results = parallel([
            'first' => static fn (): string => $first->toBytes(),
            'second' => static fn (): string => $second->toBytes(),
        ]);

        $this->assertSame('shared image', $results['first']);
        $this->assertSame('shared image', $results['second']);
        $this->assertSame(1, $resolutionCalls);
    }

    public function testClonedImagesReceiveTheOriginalTerminalSourceException(): void
    {
        $resolutionCalls = 0;
        $terminalException = new RuntimeException('Source failed.');
        $image = new Image(function () use (&$resolutionCalls, $terminalException): never {
            ++$resolutionCalls;
            usleep(5000);

            throw $terminalException;
        });
        $first = $image->using('first');
        $second = $image->using('second');

        $results = parallel([
            'first' => static function () use ($first): Throwable {
                try {
                    $first->toBytes();
                } catch (Throwable $exception) {
                    return $exception;
                }

                throw new RuntimeException('Expected source resolution to fail.');
            },
            'second' => static function () use ($second): Throwable {
                try {
                    $second->toBytes();
                } catch (Throwable $exception) {
                    return $exception;
                }

                throw new RuntimeException('Expected source resolution to fail.');
            },
        ]);

        $this->assertSame($terminalException, $results['first']);
        $this->assertSame($terminalException, $results['second']);
        $this->assertSame(1, $resolutionCalls);
    }

    public function testSingletonDriverDoesNotMixConcurrentImageOperations(): void
    {
        $container = new Container;
        $container->instance('config', new Repository([
            'images' => ['default' => 'interleaving'],
        ]));
        $driver = new InterleavingImageDriver;
        $manager = new ImageManager($container);
        $manager->extend('interleaving', static fn (): Driver => $driver);
        $container->instance('image', $manager);
        Container::setInstance($container);

        $first = (new Image('first'))->toPng();
        $second = (new Image('second'))->toWebp();

        $results = parallel([
            'first' => static fn (): string => $first->toBytes(),
            'second' => static fn (): string => $second->toBytes(),
        ]);

        $this->assertSame('first:png', $results['first']);
        $this->assertSame('second:webp', $results['second']);
        $this->assertSame($driver, $manager->driver());
    }
}

class InterleavingImageDriver implements Driver
{
    /**
     * The registered transformation handlers.
     *
     * @var array<class-string<Transformation>, callable>
     */
    private array $transformationHandlers = [];

    /**
     * Process the image contents.
     */
    public function process(string $contents, ImagePipeline $pipeline): string
    {
        usleep(5000);

        return $contents . ':' . $pipeline->output->format;
    }

    /**
     * Get the image dimensions.
     *
     * @return array{0: int, 1: int}
     */
    public function dimensions(string $contents): array
    {
        return [1, 1];
    }

    /**
     * Get the dominant image color.
     */
    public function dominantColor(string $contents): string
    {
        return '#000000';
    }

    /**
     * Register a transformation handler.
     *
     * @param class-string<Transformation> $transformation
     */
    public function transformUsing(string $transformation, callable $callback): static
    {
        $this->transformationHandlers[$transformation] = $callback;

        return $this;
    }
}
