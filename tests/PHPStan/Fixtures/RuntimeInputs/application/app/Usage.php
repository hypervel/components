<?php

declare(strict_types=1);

namespace RuntimeInputs;

use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Support\Str;
use RuntimeInputs\Exports\ExportManager;
use RuntimeInputs\Greeters\Greeter;
use RuntimeInputs\Models\Comment;

class Usage
{
    public function macro(): int
    {
        return Str::fixtureMacro();
    }

    public function mixin(): int
    {
        return Str::fixtureMixin();
    }

    public function greeting(): string
    {
        return app(Greeter::class)->bonjour();
    }

    public function facade(): string
    {
        return Fixture::shout();
    }

    public function export(): int
    {
        return app(ExportManager::class)->export();
    }

    /**
     * @param Collection<int, Comment> $comments
     */
    public function morph(Collection $comments): void
    {
        $comments->loadMorph('commentable', ['post' => ['comments' => fn (HasMany $relation) => $relation]]);
    }
}
