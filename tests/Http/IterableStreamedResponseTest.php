<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http;

use Hypervel\Http\IterableStreamedResponse;
use Hypervel\Tests\TestCase;

class IterableStreamedResponseTest extends TestCase
{
    public function testSendContentConsumesLazyChunksOnce(): void
    {
        $iterations = 0;
        $chunks = (function () use (&$iterations) {
            ++$iterations;

            yield 'first';
            yield 'second';
        })();
        $response = new IterableStreamedResponse($chunks);
        unset($chunks);

        $this->assertSame(0, $iterations);
        $content = '';
        $level = ob_get_level();

        ob_start(static function (string $chunk) use (&$content): string {
            $content .= $chunk;

            return '';
        }, 1);

        try {
            $response->sendContent();
            $response->sendContent();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        $this->assertSame('firstsecond', $content);
        $this->assertSame(1, $iterations);
        $this->assertFalse($response->streamTo(static fn (string $chunk): bool => true));
    }

    public function testStreamToStopsAndReleasesChunksWhenTheWriterFails(): void
    {
        $closed = false;
        $chunks = (function () use (&$closed) {
            try {
                yield 'first';
                yield 'second';
            } finally {
                $closed = true;
            }
        })();
        $response = new IterableStreamedResponse($chunks);
        unset($chunks);
        $written = [];

        $handled = $response->streamTo(function (string $chunk) use (&$written): bool {
            $written[] = $chunk;

            return false;
        });

        $this->assertTrue($handled);
        $this->assertSame(['first'], $written);
        $this->assertTrue($closed);
        $this->assertFalse($response->streamTo(static fn (string $chunk): bool => true));
    }

    public function testSettingACallbackReleasesRetainedChunks(): void
    {
        $closed = false;
        $chunks = (function () use (&$closed) {
            try {
                yield 'first';
            } finally {
                $closed = true;
            }
        })();
        $chunks->current();
        $response = new IterableStreamedResponse($chunks);
        unset($chunks);

        $response->setCallback(static function (): void {
            echo 'callback';
        });

        $this->assertTrue($closed);
        $this->assertFalse($response->streamTo(static fn (string $chunk): bool => true));

        ob_start();

        try {
            $response->sendContent();

            $this->assertSame('callback', ob_get_contents());
        } finally {
            ob_end_clean();
        }
    }
}
