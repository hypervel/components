<?php

declare(strict_types=1);

namespace Hypervel\Tests\Engine;

use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use WeakReference;

class HookedCurlTest extends TestCase
{
    #[DataProvider('callbackOptions')]
    public function testResetReleasesCallbackCapturesWhileKeepingTheHandleReusable(int $option): void
    {
        // @TODO Remove this skip once PHP/Swoole curl_reset() releases write/read/header
        // callback references. It currently retains their captures until replacement or destruction.
        $this->markTestSkipped('PHP/Swoole curl_reset() retains callback captures on reusable handles.');

        $handle = curl_init();
        $captured = (object) ['result' => $option === CURLOPT_READFUNCTION ? '' : 0];
        $reference = WeakReference::create($captured);

        curl_setopt($handle, $option, static fn (): int|string => $captured->result);
        unset($captured);

        // An idle pooled handle must not keep the previous request's object graph alive.
        curl_reset($handle);

        $this->assertNull($reference->get());
        $this->assertTrue(curl_setopt($handle, $option, static fn (): int|string => $option === CURLOPT_READFUNCTION ? '' : 0));
    }

    /**
     * Provide the callback options whose captures reset must release.
     */
    public static function callbackOptions(): array
    {
        return [
            'write' => [CURLOPT_WRITEFUNCTION],
            'header' => [CURLOPT_HEADERFUNCTION],
            'read' => [CURLOPT_READFUNCTION],
        ];
    }
}
