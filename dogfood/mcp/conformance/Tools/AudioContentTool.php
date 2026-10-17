<?php

declare(strict_types=1);

namespace Hypervel\Dogfood\Mcp\Conformance\Tools;

use Hypervel\Dogfood\Mcp\Conformance\Fixtures;
use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\Server\Tool;

class AudioContentTool extends Tool
{
    protected string $name = 'test_audio_content';

    protected string $description = 'Tests audio content response';

    /**
     * Return the audio content fixture.
     */
    public function handle(Request $request): Response
    {
        return Response::audio(Fixtures::AUDIO_BASE64, 'audio/wav');
    }
}
