<?php

declare(strict_types=1);

namespace Hypervel\Doctor\Results;

final class Message
{
    /**
     * The related links for the message.
     *
     * @var list<Link>
     */
    public array $links = [];

    /**
     * Create a new diagnostic message definition.
     */
    public function __construct(
        public readonly string $summary,
        public readonly ?string $remediation = null,
        public readonly ?string $confirmation = null,
    ) {
    }

    /**
     * Create a new diagnostic message definition.
     */
    public static function make(
        string $summary,
        ?string $remediation = null,
        ?string $confirmation = null,
    ): self {
        return new self($summary, $remediation, $confirmation);
    }

    /**
     * Add a related link to the message.
     */
    public function link(Link $link): self
    {
        $this->links[] = $link;

        return $this;
    }
}
