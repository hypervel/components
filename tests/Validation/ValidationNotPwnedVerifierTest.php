<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Generator;
use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Foundation\Exceptions\Handler;
use Hypervel\Http\Client\ConnectionException;
use Hypervel\Http\Client\Factory as HttpFactory;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Testing\Fakes\ExceptionHandlerFake;
use Hypervel\Testbench\TestCase;
use Hypervel\Validation\NotPwnedVerifier;
use PHPUnit\Framework\Attributes\DataProvider;

class ValidationNotPwnedVerifierTest extends TestCase
{
    #[DataProvider('dataProviderEmptyValues')]
    public function testEmptyValues(string|bool|int $password): void
    {
        $http = new HttpFactory;
        $http->fake();
        $verifier = new NotPwnedVerifier($http);

        $this->assertFalse($verifier->verify([
            'value' => $password,
            'threshold' => 0,
        ]));

        $http->assertNothingSent();
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
        $http = new HttpFactory;
        $http->fake(['api.pwnedpasswords.com/*' => HttpFactory::response('', 200)]);
        $verifier = new NotPwnedVerifier($http);

        $this->assertTrue($verifier->verify([
            'value' => 123123123,
            'threshold' => 0,
        ]));

        $http->assertSentCount(1);
    }

    public function testApiGoesDown(): void
    {
        $http = new HttpFactory;
        $http->fake(['api.pwnedpasswords.com/*' => HttpFactory::response('', 500)]);
        $verifier = new NotPwnedVerifier($http);

        $this->assertTrue($verifier->verify([
            'value' => 123123123,
            'threshold' => 0,
        ]));

        $http->assertSentCount(1);
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

        $timeout = null;
        $http = new HttpFactory;
        // Capture the request options, since recorded requests do not expose the timeout.
        $http->fake(['api.pwnedpasswords.com/*' => function (Request $request, array $options) use ($differentSuffix, &$timeout): PromiseInterface {
            $timeout = $options['timeout'];

            return HttpFactory::response($differentSuffix . ':5', 200);
        }]);
        $verifier = new NotPwnedVerifier($http);

        $this->assertTrue($verifier->verify([
            'value' => $password,
            'threshold' => 0,
        ]));

        $http->assertSent(fn (Request $request): bool => $request->url() === 'https://api.pwnedpasswords.com/range/' . $hashPrefix
            && $request->hasHeader('Add-Padding', 'true'));
        $this->assertSame(30, $timeout);
    }

    public function testDnsDown(): void
    {
        $exceptionHandler = new ExceptionHandlerFake(new Handler($this->app));
        $this->app->singleton(ExceptionHandler::class, function () use ($exceptionHandler): ExceptionHandler {
            return $exceptionHandler;
        });

        $http = new HttpFactory;
        $http->fake(HttpFactory::failedConnection());
        $verifier = new NotPwnedVerifier($http);

        $this->assertTrue($verifier->verify([
            'value' => 123123123,
            'threshold' => 0,
        ]));

        $exceptionHandler->assertReported(ConnectionException::class);
    }
}
