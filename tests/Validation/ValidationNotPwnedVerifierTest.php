<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Generator;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Http\Client\ConnectionException;
use Hypervel\Http\Client\Factory as HttpFactory;
use Hypervel\Http\Client\Response;
use Hypervel\Testbench\TestCase;
use Hypervel\Validation\NotPwnedVerifier;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;

class ValidationNotPwnedVerifierTest extends TestCase
{
    #[DataProvider('dataProviderEmptyValues')]
    public function testEmptyValues(string|bool|int $password): void
    {
        $httpFactory = m::mock(HttpFactory::class);
        $verifier = new NotPwnedVerifier($httpFactory);

        $this->assertFalse($verifier->verify([
            'value' => $password,
            'threshold' => 0,
        ]));
    }

    /**
     * Provide empty password values.
     */
    public static function dataProviderEmptyValues(): Generator
    {
        yield 'empty string' => [''];
        yield 'false' => [false];
        yield 'zero' => [0];
    }

    public function testApiResponseGoesWrong(): void
    {
        $httpFactory = m::mock(HttpFactory::class);
        $response = m::mock(Response::class);

        $httpFactory
            ->expects('withHeaders')
            ->with(['Add-Padding' => true])
            ->andReturn($httpFactory);

        $httpFactory
            ->expects('timeout')
            ->with(30)
            ->andReturn($httpFactory);

        $httpFactory->expects('get')
            ->andReturn($response);

        $response->expects('successful')
            ->andReturn(true);

        $response->expects('body')
            ->andReturn('');

        $verifier = new NotPwnedVerifier($httpFactory);

        $this->assertTrue($verifier->verify([
            'value' => 123123123,
            'threshold' => 0,
        ]));
    }

    public function testApiGoesDown(): void
    {
        $httpFactory = m::mock(HttpFactory::class);
        $response = m::mock(Response::class);

        $httpFactory
            ->expects('withHeaders')
            ->with(['Add-Padding' => true])
            ->andReturn($httpFactory);

        $httpFactory
            ->expects('timeout')
            ->with(30)
            ->andReturn($httpFactory);

        $httpFactory->expects('get')
            ->andReturn($response);

        $response->expects('successful')
            ->andReturn(false);

        $verifier = new NotPwnedVerifier($httpFactory);

        $this->assertTrue($verifier->verify([
            'value' => 123123123,
            'threshold' => 0,
        ]));
    }

    public function testMagicHashDoesNotCauseFalsePositive(): void
    {
        // "aaroZmOk" produces a SHA-1 hash that is all digits prefixed with "0E",
        // which PHP treats as scientific notation (zero) during loose comparison,
        // causing any other all-digit "0E" hash to falsely match.
        $password = 'aaroZmOk';
        $hash = strtoupper(sha1($password));
        $hashPrefix = substr($hash, 0, 5);

        $differentSuffix = '00000000000000000000000000000000000';

        $httpFactory = m::mock(HttpFactory::class);
        $response = m::mock(Response::class);

        $httpFactory
            ->expects('withHeaders')
            ->with(['Add-Padding' => true])
            ->andReturn($httpFactory);

        $httpFactory
            ->expects('timeout')
            ->with(30)
            ->andReturn($httpFactory);

        $httpFactory->expects('get')
            ->with('https://api.pwnedpasswords.com/range/' . $hashPrefix)
            ->andReturn($response);

        $response->expects('successful')
            ->andReturn(true);

        $response->expects('body')
            ->andReturn($differentSuffix . ':5');

        $verifier = new NotPwnedVerifier($httpFactory);

        $this->assertTrue($verifier->verify([
            'value' => $password,
            'threshold' => 0,
        ]));
    }

    public function testDnsDown(): void
    {
        $exception = new ConnectionException;

        $exceptionHandler = m::mock(ExceptionHandler::class);
        $exceptionHandler->expects('report')->with($exception, []);
        $this->app->singleton(ExceptionHandler::class, function () use ($exceptionHandler): ExceptionHandler {
            return $exceptionHandler;
        });

        $httpFactory = m::mock(HttpFactory::class);

        $httpFactory
            ->expects('withHeaders')
            ->with(['Add-Padding' => true])
            ->andReturn($httpFactory);

        $httpFactory
            ->expects('timeout')
            ->with(30)
            ->andReturn($httpFactory);

        $httpFactory
            ->expects('get')
            ->andThrow($exception);

        $verifier = new NotPwnedVerifier($httpFactory);
        $this->assertTrue($verifier->verify([
            'value' => 123123123,
            'threshold' => 0,
        ]));
    }
}
