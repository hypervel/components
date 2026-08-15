<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing\Profile;

use Hypervel\Testing\Profile\ExecutionFinishedSubscriber;
use Hypervel\Testing\Profile\ProfileTracker;
use Hypervel\Tests\TestCase;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use RuntimeException;

class ExecutionFinishedSubscriberTest extends TestCase
{
    #[Test]
    #[DataProvider('failedWrites')]
    public function itRejectsIncompleteProfileWrites(string $writeMode): void
    {
        ProfileWriteStreamWrapper::$writeMode = $writeMode;
        ProfileWriteStreamWrapper::$writeCount = 0;

        $tracker = new ProfileTracker;
        $tracker->start('Example test', 1.0);
        $tracker->stop('Example test', 2.0);

        $subscriber = new ExecutionFinishedSubscriber($tracker, 'profile-write://directory');
        $event = (new ReflectionClass(ExecutionFinished::class))->newInstanceWithoutConstructor();

        stream_wrapper_register('profile-write', ProfileWriteStreamWrapper::class);

        try {
            $subscriber->notify($event);
            $this->fail('The incomplete profile write was not rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringStartsWith(
                'Unable to write test profile [profile-write://directory/profile-',
                $exception->getMessage(),
            );
        } finally {
            stream_wrapper_unregister('profile-write');
        }
    }

    /**
     * Provide incomplete write modes.
     *
     * @return array<string, array{string}>
     */
    public static function failedWrites(): array
    {
        return [
            'false' => ['false'],
            'short' => ['short'],
        ];
    }
}

class ProfileWriteStreamWrapper
{
    /** @var null|resource */
    public mixed $context = null;

    public static string $writeMode = 'false';

    public static int $writeCount = 0;

    /**
     * Open the stream.
     */
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    /**
     * Write bytes to the stream.
     */
    public function stream_write(string $data): int|false
    {
        if (static::$writeMode === 'false' || static::$writeCount++ > 0) {
            return false;
        }

        return max(0, strlen($data) - 1);
    }

    /**
     * Return stream metadata.
     *
     * @return array{mode: int}
     */
    public function stream_stat(): array
    {
        return ['mode' => 0100666];
    }

    /**
     * Return URL metadata.
     *
     * @return array{mode: int}
     */
    public function url_stat(string $path, int $flags): array
    {
        return ['mode' => 0040777];
    }
}
