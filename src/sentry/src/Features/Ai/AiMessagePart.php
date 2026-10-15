<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Features\Ai;

use JsonSerializable;

class AiMessagePart implements JsonSerializable
{
    private ?string $id = null;

    private ?string $name = null;

    private ?string $content = null;

    private ?string $arguments = null;

    private ?string $modality = null;

    private ?string $mimeType = null;

    /**
     * Create a part of the given type.
     */
    public function __construct(private string $type)
    {
    }

    /**
     * Return the part type.
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Set the tool-call ID.
     */
    public function setId(?string $id): self
    {
        $this->id = $id;
        return $this;
    }

    /**
     * Set the part name.
     */
    public function setName(?string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Set the content.
     */
    public function setContent(?string $content): self
    {
        $this->content = $content;
        return $this;
    }

    /**
     * Return the content.
     */
    public function getContent(): ?string
    {
        return $this->content;
    }

    /**
     * Set the encoded tool arguments.
     */
    public function setArguments(?string $arguments): self
    {
        $this->arguments = $arguments;
        return $this;
    }

    /**
     * Return the encoded tool arguments.
     */
    public function getArguments(): ?string
    {
        return $this->arguments;
    }

    /**
     * Set the input modality.
     */
    public function setModality(?string $modality): self
    {
        $this->modality = $modality;
        return $this;
    }

    /**
     * Set the declared MIME type.
     */
    public function setMimeType(?string $mimeType): self
    {
        $this->mimeType = $mimeType;
        return $this;
    }

    /**
     * Return the non-null part fields.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $data = ['type' => $this->type];
        if ($this->id !== null) {
            $data['id'] = $this->id;
        }
        if ($this->name !== null) {
            $data['name'] = $this->name;
        }
        if ($this->content !== null) {
            $data['content'] = $this->content;
        }
        if ($this->arguments !== null) {
            $data['arguments'] = $this->arguments;
        }
        if ($this->modality !== null) {
            $data['modality'] = $this->modality;
        }
        if ($this->mimeType !== null) {
            $data['mime_type'] = $this->mimeType;
        }
        return $data;
    }

    /**
     * Return the part in Sentry's serialized format.
     *
     * @return array<string, string>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
