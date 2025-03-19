<?php

declare(strict_types=1);

namespace Hypervel\Telescope;

use DateTimeInterface;
use Hyperf\Context\ApplicationContext;
use Hyperf\Stringable\Str;
use Hypervel\Auth\Contracts\Authenticatable;
use Hypervel\Telescope\Contracts\EntriesRepository;

class IncomingEntry
{
    /**
     * The entry's UUID.
     */
    public string $uuid;

    /**
     * The entry's batch ID.
     */
    public ?string $batchId = null;

    /**
     * The entry's type.
     */
    public ?string $type = null;

    /**
     * The entry's family hash.
     */
    public ?string $familyHash = null;

    /**
     * The currently authenticated user, if applicable.
     */
    public mixed $user = null;

    /**
     * The entry's content.
     */
    public array|string $content = [];

    /**
     * The entry's tags.
     */
    public array $tags = [];

    /**
     * The DateTime that indicates when the entry was recorded.
     */
    public DateTimeInterface $recordedAt;

    /**
     * Create a new incoming entry instance.
     */
    public function __construct(array $content, ?string $uuid = null)
    {
        $this->uuid = $uuid ?: (string) Str::orderedUuid();

        $this->recordedAt = now();

        $this->content = array_merge($content, ['hostname' => gethostname()]);

        // $this->tags = ['hostname:'.gethostname()];
    }

    /**
     * Create a new entry instance.
     */
    public static function make(mixed ...$arguments): static
    {
        return new static(...$arguments);
    }

    /**
     * Assign the entry a given batch ID.
     */
    public function batchId(string $batchId): static
    {
        $this->batchId = $batchId;

        return $this;
    }

    /**
     * Assign the entry a given type.
     */
    public function type(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Assign the entry a family hash.
     */
    public function withFamilyHash(?string $familyHash): static
    {
        $this->familyHash = $familyHash;

        return $this;
    }

    /**
     * Set the currently authenticated user.
     */
    public function user(Authenticatable $user): static
    {
        $this->user = $user;

        $this->content = array_merge($this->content, [
            'user' => [
                'id' => $user->getAuthIdentifier(),
                'name' => $user->name ?? null,
                'email' => $user->email ?? null,
            ],
        ]);

        $this->tags(['Auth:' . $user->getAuthIdentifier()]);

        return $this;
    }

    /**
     * Merge tags into the entry's existing tags.
     */
    public function tags(array $tags): static
    {
        $this->tags = array_unique(array_merge($this->tags, $tags));

        return $this;
    }

    /**
     * Determine if the incoming entry has a monitored tag.
     */
    public function hasMonitoredTag(): bool
    {
        if (! empty($this->tags)) {
            return ApplicationContext::getContainer()
                ->get(EntriesRepository::class)
                ->isMonitoring($this->tags);
        }

        return false;
    }

    /**
     * Determine if the incoming entry is a request.
     */
    public function isRequest(): bool
    {
        return $this->type === EntryType::REQUEST;
    }

    /**
     * Determine if the incoming entry is a failed request.
     */
    public function isFailedRequest(): bool
    {
        return $this->type === EntryType::REQUEST
            && ($this->content['response_status'] ?? 200) >= 500;
    }

    /**
     * Determine if the incoming entry is a query.
     */
    public function isQuery(): bool
    {
        return $this->type === EntryType::QUERY;
    }

    /**
     * Determine if the incoming entry is a slow query.
     */
    public function isSlowQuery(): bool
    {
        return $this->type === EntryType::QUERY && ($this->content['slow'] ?? false);
    }

    /**
     * Determine if the incoming entry is a event entry.
     */
    public function isEvent(): bool
    {
        return $this->type === EntryType::EVENT;
    }

    /**
     * Determine if the incoming entry is a cache entry.
     */
    public function isCache(): bool
    {
        return $this->type === EntryType::CACHE;
    }

    /**
     * Determine if the incoming entry is an authorization gate check.
     */
    public function isGate(): bool
    {
        return $this->type === EntryType::GATE;
    }

    /**
     * Determine if the incoming entry is a failed job.
     */
    public function isFailedJob(): bool
    {
        return $this->type === EntryType::JOB
            && ($this->content['status'] ?? null) === 'failed';
    }

    /**
     * Determine if the incoming entry is a reportable exception.
     */
    public function isReportableException(): bool
    {
        return false;
    }

    /**
     * Determine if the incoming entry is an exception.
     */
    public function isException(): bool
    {
        return false;
    }

    /**
     * Determine if the incoming entry is a dump.
     */
    public function isDump(): bool
    {
        return false;
    }

    /**
     * Determine if the incoming entry is a log entry.
     */
    public function isLog(): bool
    {
        return $this->type === EntryType::LOG;
    }

    /**
     * Determine if the incoming entry is a scheduled task.
     */
    public function isScheduledTask(): bool
    {
        return $this->type === EntryType::SCHEDULED_TASK;
    }

    /**
     * Determine if the incoming entry is an client request.
     */
    public function isClientRequest(): bool
    {
        return $this->type === EntryType::CLIENT_REQUEST;
    }

    /**
     * Get the family look-up hash for the incoming entry.
     */
    public function familyHash(): ?string
    {
        return $this->familyHash;
    }

    /**
     * Get an array representation of the entry for storage.
     */
    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'batch_id' => $this->batchId,
            'family_hash' => $this->familyHash,
            'type' => $this->type,
            'content' => $this->content,
            'created_at' => $this->recordedAt->toDateTimeString(), // @phpstan-ignore-line
        ];
    }
}
