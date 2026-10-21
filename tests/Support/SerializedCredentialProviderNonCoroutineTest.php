<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Aws\Credentials\Credentials;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Support\Aws\SerializedCredentialProvider;
use Hypervel\Tests\TestCase;

class SerializedCredentialProviderNonCoroutineTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testProviderFinishesItsWorkOutsideCoroutines(): void
    {
        $credentials = new Credentials('console', 'secret');
        $provider = new SerializedCredentialProvider(static function () use ($credentials): PromiseInterface {
            $promise = new Promise(static function () use (&$promise, $credentials): void {
                $promise->resolve($credentials);
            });

            return $promise;
        });

        $result = $provider();

        $this->assertSame(PromiseInterface::FULFILLED, $result->getState());
        $this->assertSame($credentials, $result->wait());
        $this->assertSame($credentials, $provider()->wait());
    }
}
