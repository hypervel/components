<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit\Engines;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Hypervel\Scout\Engines\MeilisearchRetryPolicy;
use Hypervel\Tests\TestCase;
use RuntimeException;

class MeilisearchRetryPolicyTest extends TestCase
{
    public function testNextDelayMsReturnsBaseForFirstAttempt()
    {
        $this->assertSame(100, MeilisearchRetryPolicy::nextDelayMs(1, 100));
        $this->assertSame(50, MeilisearchRetryPolicy::nextDelayMs(1, 50));
        $this->assertSame(250, MeilisearchRetryPolicy::nextDelayMs(1, 250));
    }

    public function testNextDelayMsDoublesEachAttempt()
    {
        $this->assertSame(100, MeilisearchRetryPolicy::nextDelayMs(1, 100));
        $this->assertSame(200, MeilisearchRetryPolicy::nextDelayMs(2, 100));
        $this->assertSame(400, MeilisearchRetryPolicy::nextDelayMs(3, 100));
        $this->assertSame(800, MeilisearchRetryPolicy::nextDelayMs(4, 100));
    }

    public function testNextDelayMsScalesWithAlternateBase()
    {
        $this->assertSame(50, MeilisearchRetryPolicy::nextDelayMs(1, 50));
        $this->assertSame(100, MeilisearchRetryPolicy::nextDelayMs(2, 50));
        $this->assertSame(200, MeilisearchRetryPolicy::nextDelayMs(3, 50));
    }

    public function testShouldRetryOnConnectException()
    {
        $exception = new ConnectException('Connection refused', new Request('POST', 'http://localhost'));

        $this->assertTrue(MeilisearchRetryPolicy::shouldRetry(null, $exception));
    }

    public function testShouldRetryOn5xxResponses()
    {
        $this->assertTrue(MeilisearchRetryPolicy::shouldRetry(new Response(500), null));
        $this->assertTrue(MeilisearchRetryPolicy::shouldRetry(new Response(502), null));
        $this->assertTrue(MeilisearchRetryPolicy::shouldRetry(new Response(503), null));
        $this->assertTrue(MeilisearchRetryPolicy::shouldRetry(new Response(504), null));
    }

    public function testShouldRetryOn429Response()
    {
        $this->assertTrue(MeilisearchRetryPolicy::shouldRetry(new Response(429), null));
    }

    public function testShouldNotRetryOnSuccessfulResponses()
    {
        $this->assertFalse(MeilisearchRetryPolicy::shouldRetry(new Response(200), null));
        $this->assertFalse(MeilisearchRetryPolicy::shouldRetry(new Response(201), null));
        $this->assertFalse(MeilisearchRetryPolicy::shouldRetry(new Response(202), null));
        $this->assertFalse(MeilisearchRetryPolicy::shouldRetry(new Response(204), null));
    }

    public function testShouldNotRetryOnRedirectResponses()
    {
        $this->assertFalse(MeilisearchRetryPolicy::shouldRetry(new Response(301), null));
        $this->assertFalse(MeilisearchRetryPolicy::shouldRetry(new Response(302), null));
        $this->assertFalse(MeilisearchRetryPolicy::shouldRetry(new Response(304), null));
    }

    public function testShouldNotRetryOnClientErrors()
    {
        $this->assertFalse(MeilisearchRetryPolicy::shouldRetry(new Response(400), null));
        $this->assertFalse(MeilisearchRetryPolicy::shouldRetry(new Response(401), null));
        $this->assertFalse(MeilisearchRetryPolicy::shouldRetry(new Response(403), null));
        $this->assertFalse(MeilisearchRetryPolicy::shouldRetry(new Response(404), null));
        $this->assertFalse(MeilisearchRetryPolicy::shouldRetry(new Response(422), null));
    }

    public function testShouldNotRetryOnUnrelatedException()
    {
        $exception = new RuntimeException('Something else went wrong');

        $this->assertFalse(MeilisearchRetryPolicy::shouldRetry(null, $exception));
    }

    public function testShouldNotRetryWhenBothResponseAndExceptionAreNull()
    {
        $this->assertFalse(MeilisearchRetryPolicy::shouldRetry(null, null));
    }
}
