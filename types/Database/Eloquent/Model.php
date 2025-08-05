<?php

declare(strict_types=1);

namespace Hypervel\Types\Model;

use Hypervel\Database\Eloquent\Collection;
// use Hypervel\Database\Eloquent\HasCollection; // HasCollection not supported in Hyperf
use Hypervel\Database\Eloquent\Model;
use User;

use function PHPStan\Testing\assertType;

function test(User $user, Post $post, Comment $comment, Article $article): void
{
    assertType('Hypervel\Database\Eloquent\Builder<User>', User::query());
    assertType('Hypervel\Database\Eloquent\Builder<User>', $user->newQuery());
    assertType('Hypervel\Database\Eloquent\Builder<User>', $user->withTrashed());
    assertType('Hypervel\Database\Eloquent\Builder<User>', $user->onlyTrashed());
    assertType('Hypervel\Database\Eloquent\Builder<User>', $user->withoutTrashed());

    assertType('Hypervel\Database\Eloquent\Collection<(int|string), User>', $user->newCollection([new User()]));
    assertType('Hypervel\Types\Model\Comments', $comment->newCollection([new Comment()]));
    assertType('Hypervel\Database\Eloquent\Collection<(int|string), Hypervel\Types\Model\Post>', $post->newCollection(['foo' => new Post()]));
    assertType('Hypervel\Database\Eloquent\Collection<(int|string), Hypervel\Types\Model\Article>', $article->newCollection([new Article()]));
    assertType('Hypervel\Types\Model\Comments', $comment->newCollection([new Comment()]));

    assertType('bool|null', $user->restore());
}

class Post extends Model
{
    protected static string $collectionClass = Posts::class;
}

/**
 * @template TKey of array-key
 * @template TModel of Post
 *
 * @extends Collection<TKey, TModel> */
class Posts extends Collection
{
}

final class Comment extends Model
{
    /** @param  array<array-key, Comment>  $models */
    public function newCollection(array $models = []): Comments
    {
        return new Comments($models);
    }
}

/** @extends Collection<array-key, Comment> */
final class Comments extends Collection
{
}

class Article extends Model
{
}

/**
 * @template TKey of array-key
 * @template TModel of Article
 *
 * @extends Collection<TKey, TModel> */
class Articles extends Collection
{
}
