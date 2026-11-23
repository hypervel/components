<?php

namespace ModelFactories;

use App\Post;
use App\User;
use Hypervel\Database\Eloquent\Factories\Sequence;

function test(): void {
    User::factory()->create(new Sequence());
    User::factory()->createQuietly(new Sequence());
    User::factory()->make(new Sequence());

    User::factory()->hasPosts(['title' => 'a'], ['title' => 'b']);
    User::factory()->trashed('2026-01-01 00:00:00');
    Post::factory()->trashed();
    User::factory()->hasGetTable();
}
