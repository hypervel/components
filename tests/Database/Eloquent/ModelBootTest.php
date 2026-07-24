<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Eloquent\ModelBootTest;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine;
use Hypervel\Tests\TestCase;
use LogicException;
use Mockery as m;
use ReflectionProperty;
use RuntimeException;
use Throwable;

use function Hypervel\Coroutine\go;

class ModelBootTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Model::flushState();
        RecursiveBootModel::reset();
        PostPublicationConstructionModel::reset();
        ConcurrentBootModel::reset();
        PostPublicationFailureModel::reset();
    }

    public function testSameOwnerRecursionBeforePublicationThrowsAndLaterRetries(): void
    {
        try {
            new RecursiveBootModel;
            $this->fail('Expected recursive model boot to fail.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'The [Hypervel\Database\Eloquent\Model::bootIfNotBooted] method may not be called on model ['
                    . RecursiveBootModel::class . '] while it is being booted.',
                $exception->getMessage()
            );
        }

        RecursiveBootModel::$recurse = false;

        $this->assertInstanceOf(RecursiveBootModel::class, new RecursiveBootModel);
        $this->assertSame(2, RecursiveBootModel::$bootCalls);
    }

    public function testSameOwnerCanConstructModelsAfterPublication(): void
    {
        new PostPublicationConstructionModel;

        $this->assertTrue(PostPublicationConstructionModel::$constructedInBooted);
        $this->assertTrue(PostPublicationConstructionModel::$constructedInCallback);
        $this->assertSame(1, PostPublicationConstructionModel::$bootCalls);
    }

    public function testSiblingCoroutineWaitsForCompleteBootPublication(): void
    {
        ConcurrentBootModel::$bootStarted = new Channel(1);
        ConcurrentBootModel::$continueBoot = new Channel(1);
        $firstCompleted = new Channel(1);
        $secondEntered = new Channel(1);
        $secondCompleted = new Channel(1);

        $firstCoroutine = go(static function () use ($firstCompleted): void {
            try {
                new ConcurrentBootModel;
                $firstCompleted->push(true);
            } catch (Throwable $throwable) {
                $firstCompleted->push($throwable);
            }
        });

        $this->assertTrue(ConcurrentBootModel::$bootStarted->pop(1.0));

        $secondCoroutine = go(static function () use ($secondEntered, $secondCompleted): void {
            $secondEntered->push(true);

            try {
                new ConcurrentBootModel;
                $secondCompleted->push(true);
            } catch (Throwable $throwable) {
                $secondCompleted->push($throwable);
            }
        });

        $this->assertTrue($secondEntered->pop(1.0));
        $this->assertFalse($secondCompleted->pop(0.01));

        ConcurrentBootModel::$continueBoot->push(true);

        $this->assertTrue($firstCompleted->pop(1.0));
        $this->assertTrue($secondCompleted->pop(1.0));
        $this->assertFalse(Coroutine::exists($firstCoroutine));
        $this->assertFalse(Coroutine::exists($secondCoroutine));
        $this->assertSame(1, ConcurrentBootModel::$bootCalls);
    }

    public function testBootedHookFailureLeavesTheModelPublishedAndClearsOwnership(): void
    {
        $failure = new RuntimeException('booted hook failure');
        PostPublicationFailureModel::$failurePhase = 'hook';
        PostPublicationFailureModel::$failure = $failure;

        try {
            new PostPublicationFailureModel;
            $this->fail('Expected the booted hook to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        PostPublicationFailureModel::$failurePhase = null;

        $this->assertInstanceOf(PostPublicationFailureModel::class, new PostPublicationFailureModel);
        $this->assertSame(1, PostPublicationFailureModel::$bootCalls);
    }

    public function testBootedCallbackFailureLeavesTheModelPublishedAndClearsOwnership(): void
    {
        $failure = new RuntimeException('booted callback failure');
        PostPublicationFailureModel::$failurePhase = 'callback';
        PostPublicationFailureModel::$failure = $failure;

        try {
            new PostPublicationFailureModel;
            $this->fail('Expected the booted callback to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        PostPublicationFailureModel::$failurePhase = null;

        $this->assertInstanceOf(PostPublicationFailureModel::class, new PostPublicationFailureModel);
        $this->assertSame(1, PostPublicationFailureModel::$bootCalls);
    }

    public function testBootedEventFailureLeavesTheModelPublishedAndClearsOwnership(): void
    {
        $failure = new RuntimeException('booted event failure');
        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->with('eloquent.booting: ' . PostPublicationFailureModel::class, m::type(PostPublicationFailureModel::class))
            ->andReturnNull();
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->with('eloquent.booted: ' . PostPublicationFailureModel::class, m::type(PostPublicationFailureModel::class))
            ->andThrow($failure);
        Model::setEventDispatcher($dispatcher);

        try {
            new PostPublicationFailureModel;
            $this->fail('Expected the booted event to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        } finally {
            Model::unsetEventDispatcher();
        }

        $this->assertInstanceOf(PostPublicationFailureModel::class, new PostPublicationFailureModel);
        $this->assertSame(1, PostPublicationFailureModel::$bootCalls);
    }

    public function testClearBootedModelsClearsBootOwnership(): void
    {
        $booting = new ReflectionProperty(Model::class, 'booting');
        $booting->setValue(null, [RecursiveBootModel::class => Coroutine::id()]);

        Model::clearBootedModels();

        $this->assertSame([], $booting->getValue());
    }
}

class RecursiveBootModel extends Model
{
    public static bool $recurse = true;

    public static int $bootCalls = 0;

    protected static function booting(): void
    {
        ++static::$bootCalls;

        if (static::$recurse) {
            new static;
        }
    }

    public static function reset(): void
    {
        static::$recurse = true;
        static::$bootCalls = 0;
    }
}

class PostPublicationConstructionModel extends Model
{
    public static bool $constructedInBooted = false;

    public static bool $constructedInCallback = false;

    public static int $bootCalls = 0;

    protected static function boot(): void
    {
        ++static::$bootCalls;
        parent::boot();

        static::whenBooted(function (): void {
            static::$constructedInCallback = new static instanceof static;
        });
    }

    protected static function booted(): void
    {
        static::$constructedInBooted = new static instanceof static;
    }

    public static function reset(): void
    {
        static::$constructedInBooted = false;
        static::$constructedInCallback = false;
        static::$bootCalls = 0;
    }
}

class ConcurrentBootModel extends Model
{
    public static ?Channel $bootStarted = null;

    public static ?Channel $continueBoot = null;

    public static int $bootCalls = 0;

    protected static function booting(): void
    {
        ++static::$bootCalls;
        static::$bootStarted?->push(true);
        static::$continueBoot?->pop(1.0);
    }

    public static function reset(): void
    {
        static::$bootStarted = null;
        static::$continueBoot = null;
        static::$bootCalls = 0;
    }
}

class PostPublicationFailureModel extends Model
{
    public static ?string $failurePhase = null;

    public static ?RuntimeException $failure = null;

    public static int $bootCalls = 0;

    protected static function boot(): void
    {
        ++static::$bootCalls;
        parent::boot();

        static::whenBooted(static function (): void {
            if (static::$failurePhase === 'callback') {
                throw static::$failure;
            }
        });
    }

    protected static function booted(): void
    {
        if (static::$failurePhase === 'hook') {
            throw static::$failure;
        }
    }

    public static function reset(): void
    {
        static::$failurePhase = null;
        static::$failure = null;
        static::$bootCalls = 0;
    }
}
