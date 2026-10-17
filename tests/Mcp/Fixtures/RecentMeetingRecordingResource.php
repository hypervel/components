<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Fixtures;

use Hypervel\Mcp\Server\Resource;

class RecentMeetingRecordingResource extends Resource
{
    /**
     * Return the resource description.
     */
    public function description(): string
    {
        return 'The most recent meeting recording';
    }

    /**
     * Return the recording's binary content.
     */
    public function handle(): string
    {
        return "This is a test resource.\0dummy-binary-data";
    }

    /**
     * Return the recording's URI.
     */
    public function uri(): string
    {
        return 'file://resources/recent-meeting-recording.mp4';
    }

    /**
     * Return the recording's MIME type.
     */
    public function mimeType(): string
    {
        return 'video/mp4';
    }
}
