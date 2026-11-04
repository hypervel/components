<?php

namespace App;

use Database\Factories\Post\PostFactory;
use Hypervel\Database\Eloquent\Factories\HasFactory;
use Hypervel\Database\Eloquent\Factories\Factory;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Eloquent\Relations\MorphMany;
use Hypervel\Database\Eloquent\Relations\MorphOne;
use Hypervel\Database\Eloquent\Relations\MorphToMany;

class Post extends Model
{
    use HasFactory;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /** @return MorphToMany<Tag, $this> */
    public function tags(): MorphToMany
    {
        return $this->morphedByMany(Tag::class, 'taggable');
    }

    /** @return MorphOne<Image, $this> */
    public function image(): MorphOne
    {
        return $this->morphOne(Image::class, 'imageable');
    }

    /** @return MorphMany<Comment, $this> */
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    /** @return MorphOne<Comment, $this> */
    public function latestComment(): MorphOne
    {
        return $this->comments()->one();
    }

    /**
     * @return PostBuilder<Post>
     */
    public static function query(): PostBuilder
    {
        return parent::query();
    }

    /**
     * @param  \Hypervel\Database\Query\Builder  $query
     * @return PostBuilder<Post>
     */
    public function newEloquentBuilder($query): PostBuilder
    {
        return new PostBuilder($query);
    }

    /** @return PostFactory */
    protected static function newFactory(): Factory
    {
        return PostFactory::new();
    }
}
