<?php

declare(strict_types=1);

namespace Hypervel\Telescope;

use Carbon\CarbonInterface;
use Hyperf\Collection\Collection;
use JsonSerializable;

class EntryResult implements JsonSerializable
{
    /**
     * The generated URL to the entry user's avatar.
     */
    protected ?string $avatar = null;

    /**
     * Create a new entry result instance.
     *
     * @param mixed $id the entry's primary key
     * @param mixed $sequence the entry's sequence
     * @param string $batchId the entry's batch ID
     * @param string $type the entry's type
     * @param null|string $familyHash the entry's family hash
     * @param array $content the entry's content
     * @param \Carbon\Carbon|\Carbon\CarbonInterface $createdAt the datetime that the entry was recorded
     * @param array $tags the tags assigned to the entry
     */
    public function __construct(
        public mixed $id,
        public mixed $sequence,
        public string $batchId,
        public string $type,
        public ?string $familyHash,
        public array $content,
        public CarbonInterface $createdAt,
        private $tags = []
    ) {
    }

    /**
     * Set the URL to the entry user's avatar.
     */
    public function generateAvatar(): static
    {
        if (! is_array($user = $this->content['user'] ?? [])) {
            $user = [];
        }

        $this->avatar = Avatar::url($user);

        return $this;
    }

    /**
     * Get the array representation of the entry.
     */
    public function jsonSerialize(): array
    {
        return Collection::make([
            'id' => $this->id,
            'sequence' => $this->sequence,
            'batch_id' => $this->batchId,
            'type' => $this->type,
            'content' => $this->content,
            'tags' => $this->tags,
            'family_hash' => $this->familyHash,
            'created_at' => $this->createdAt->toDateTimeString(),
        ])->when($this->avatar, function ($items) {
            return $items->mergeRecursive([
                'content' => [
                    'user' => [
                        'avatar' => $this->avatar,
                    ],
                ],
            ]);
        })->all();
    }
}
