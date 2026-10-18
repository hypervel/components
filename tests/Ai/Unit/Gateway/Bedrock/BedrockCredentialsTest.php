<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Gateway\Bedrock;

use Aws\Credentials\Credentials;
use Aws\Credentials\CredentialsInterface;
use Hypervel\Ai\Gateway\Bedrock\BedrockCredentials;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

use function Hypervel\Coroutine\parallel;

class BedrockCredentialsTest extends TestCase
{
    public function testConstantCredentialsAreReused(): void
    {
        $holder = new BedrockCredentials;
        $credentials = new Credentials('key', 'secret');
        $calls = 0;
        $resolve = function () use ($credentials, &$calls): CredentialsInterface {
            ++$calls;

            return $credentials;
        };

        $this->assertSame($credentials, $holder->get($resolve));
        $this->assertSame($credentials, $holder->get($resolve));
        $this->assertSame(1, $calls);
    }

    public function testConcurrentWaitersReuseShortLivedCredentials(): void
    {
        $holder = new BedrockCredentials;
        $credentials = new Credentials('key', 'secret', 'token', time() + 30);
        $calls = 0;
        $resolve = function () use ($credentials, &$calls): CredentialsInterface {
            ++$calls;
            usleep(1000);

            return $credentials;
        };

        $results = parallel([
            fn (): CredentialsInterface => $holder->get($resolve),
            fn (): CredentialsInterface => $holder->get($resolve),
            fn (): CredentialsInterface => $holder->get($resolve),
        ]);

        $this->assertSame([$credentials, $credentials, $credentials], $results);
        $this->assertSame(1, $calls);
    }

    #[DataProvider('refreshFailures')]
    public function testFailedRefreshClearsTheOldValueAndReleasesTheLock(Throwable $failure): void
    {
        $holder = new BedrockCredentials;
        $oldCredentials = new Credentials('old', 'secret', 'token', time() + 30);
        $holder->get(fn (): CredentialsInterface => $oldCredentials);
        $credentials = new Credentials('new', 'secret', 'token', time() + 3600);
        $results = parallel([
            function () use ($holder, $failure): Throwable {
                try {
                    $holder->get(static function () use ($failure): never {
                        usleep(1000);

                        throw $failure;
                    });
                } catch (Throwable $exception) {
                    return $exception;
                }
            },
            fn (): CredentialsInterface => $holder->get(fn (): CredentialsInterface => $credentials, 1),
        ]);

        $this->assertSame([$failure, $credentials], $results);
        $this->assertSame($credentials, $holder->get(static fn (): never => throw new RuntimeException('Unexpected refresh.')));
    }

    /**
     * Provide errors that can interrupt credential refresh.
     */
    public static function refreshFailures(): array
    {
        return [
            'failure' => [new RuntimeException('Credential source failed.')],
            'cancellation' => [new CanceledException('Credential refresh was canceled.')],
        ];
    }
}
