<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Skills;

use Hypervel\Mcp\Response;
use Hypervel\Mcp\Server\Resource;
use Hypervel\Mcp\Server\Skill;
use Symfony\Component\Mime\MimeTypes;

class SkillResource extends Resource
{
    /**
     * Create a resource backed by a skill file.
     */
    public function __construct(
        public readonly Skill $skill,
        public readonly string $file,
        string $uri,
        string $name,
        string $title,
        string $description,
    ) {
        $this->uri = $uri;
        $this->name = $name;
        $this->title = $title;
        $this->description = $description;
    }

    /**
     * Get the resource MIME type based on its file extension.
     */
    public function mimeType(): string
    {
        return MimeTypes::getDefault()->getMimeTypes(pathinfo($this->file, PATHINFO_EXTENSION))[0] ?? 'application/octet-stream';
    }

    /**
     * Read and return the skill file contents.
     */
    public function handle(): Response
    {
        $contents = $this->skill->read($this->file);

        return mb_check_encoding($contents, 'UTF-8') ? Response::text($contents) : Response::blob($contents);
    }
}
