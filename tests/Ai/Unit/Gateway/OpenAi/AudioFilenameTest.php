<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Gateway\OpenAi;

use Hypervel\Ai\Contracts\Files\TranscribableAudio;
use Hypervel\Ai\Files\Base64Audio;
use Hypervel\Ai\Gateway\OpenAi\OpenAiGateway;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Tests\TestCase;
use Mockery as m;
use ReflectionMethod;

function callAudioFilename(TranscribableAudio $audio): string
{
    $gateway = new OpenAiGateway(m::mock(Dispatcher::class));

    $method = new ReflectionMethod($gateway, 'audioFilename');

    return $method->invoke($gateway, $audio);
}

class AudioFilenameTest extends TestCase
{
    public function testWebmAudioGetsWebmExtension(): void
    {
        $audio = new Base64Audio(base64_encode('fake'), 'audio/webm');

        $this->assertSame('audio.webm', callAudioFilename($audio));
    }

    public function testOggAudioGetsOggExtension(): void
    {
        $audio = new Base64Audio(base64_encode('fake'), 'audio/ogg');

        $this->assertSame('audio.ogg', callAudioFilename($audio));
    }

    public function testWavAudioGetsWavExtension(): void
    {
        $audio = new Base64Audio(base64_encode('fake'), 'audio/wav');

        $this->assertSame('audio.wav', callAudioFilename($audio));
    }

    public function testM4aAudioGetsM4aExtension(): void
    {
        $audio = new Base64Audio(base64_encode('fake'), 'audio/m4a');

        $this->assertSame('audio.m4a', callAudioFilename($audio));
    }

    public function testFlacAudioGetsFlacExtension(): void
    {
        $audio = new Base64Audio(base64_encode('fake'), 'audio/flac');

        $this->assertSame('audio.flac', callAudioFilename($audio));
    }

    public function testMp3AudioGetsMp3Extension(): void
    {
        $audio = new Base64Audio(base64_encode('fake'), 'audio/mpeg');

        $this->assertSame('audio.mp3', callAudioFilename($audio));
    }

    public function testUnknownMimeDefaultsToMp3(): void
    {
        $audio = new Base64Audio(base64_encode('fake'), 'audio/unknown');

        $this->assertSame('audio.mp3', callAudioFilename($audio));
    }

    public function testNullMimeDefaultsToMp3(): void
    {
        $audio = new Base64Audio(base64_encode('fake'));

        $this->assertSame('audio.mp3', callAudioFilename($audio));
    }

    public function testCustomNameViaAsMethodIsRespected(): void
    {
        $audio = (new Base64Audio(base64_encode('fake'), 'audio/webm'))->as('my-recording.webm');

        $this->assertSame('my-recording.webm', callAudioFilename($audio));
    }
}
