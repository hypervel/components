<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Http;

use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Sentry\Http\SetRequestIpMiddleware;
use Hypervel\Tests\Sentry\TestCase;

class SetRequestIpMiddlewareTest extends TestCase
{
    public function testIpAddressIsSetWhenPIIShouldBeSent(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => true,
        ]);

        $this->handleRequest();

        $this->assertSame('1.2.3.4', $this->getCurrentSentryScope()->getUser()->getIpAddress());
    }

    public function testIpAddressIsNotSetWhenPIIShouldNotBeSent(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => false,
        ]);

        $this->handleRequest();

        $this->assertNull($this->getCurrentSentryScope()->getUser());
    }

    public function testIpAddressIsSetWithDataCollectionDefaults(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => false,
            'sentry.data_collection' => [],
        ]);

        $this->handleRequest();

        $this->assertSame('1.2.3.4', $this->getCurrentSentryScope()->getUser()->getIpAddress());
    }

    public function testIpAddressIsNotSetWhenUserInfoIsDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => true,
            'sentry.data_collection' => [
                'user_info' => false,
            ],
        ]);

        $this->handleRequest();

        $this->assertNull($this->getCurrentSentryScope()->getUser());
    }

    /**
     * Pass a request from a known IP address through the middleware.
     */
    private function handleRequest(): void
    {
        $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '1.2.3.4']);

        (new SetRequestIpMiddleware)->handle($request, static function (): Response {
            return new Response;
        });
    }
}
