<?php

declare(strict_types=1);

use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Pagination\CursorPaginator;
use Hypervel\Pagination\LengthAwarePaginator;
use Hypervel\Pagination\Paginator;
use Hypervel\Support\Collection;

use function PHPStan\Testing\assertType;

$items = new EloquentCollection([new Post, new Post, new Post]);

/** @var Paginator<int, Post> $paginator */
$paginator = new Paginator($items, 1, 1);

assertType('array<int, Post>', $paginator->items());
assertType('ArrayIterator<int, Post>', $paginator->getIterator());

assertType('string|null', $paginator->fragment());
assertType('Hypervel\Pagination\Paginator<int, Post>', $paginator->fragment('foo'));
assertType('Hypervel\Pagination\Paginator<int, Post>', $paginator->loadMorph('parentable', [Post::class => ['comments' => function ($relation) {
    assertType('Hypervel\Database\Eloquent\Relations\Relation<*, *, *>', $relation);
}]]));
assertType('Hypervel\Pagination\Paginator<int, Post>', $paginator->loadMorphCount('parentable', [Post::class => ['comments' => function ($query) {
    assertType('Hypervel\Database\Eloquent\Builder<*>', $query);
}]]));
assertType('Hypervel\Pagination\Paginator<int, Post>', $paginator->loadMorph('parentable', [Post::class => 'comments']));
assertType('Hypervel\Pagination\Paginator<int, Post>', $paginator->loadMorphCount('parentable', [Post::class => 'comments']));

foreach (['post', 2] as $alias) {
    assertType('Hypervel\Pagination\Paginator<int, Post>', $paginator->loadMorph('parentable', [$alias => ['comments']]));
    assertType('Hypervel\Pagination\Paginator<int, Post>', $paginator->loadMorphCount('parentable', [$alias => ['comments' => []]]));
}

$paginator->each(function ($post) {
    assertType('Post', $post);
});

foreach ($paginator as $post) {
    assertType('Post', $post);
}

$paginatorWithReplacedCollection = clone $paginator;
$paginatorWithReplacedCollection->setCollection(new Collection([
    'first' => ['id' => 1],
]));

assertType('Hypervel\Pagination\Paginator<string, array{id: 1}>', $paginatorWithReplacedCollection);

/** @var LengthAwarePaginator<int, Post> $lengthAwarePaginator */
$lengthAwarePaginator = new LengthAwarePaginator($items, 1, 1);

assertType('array<int, Post>', $lengthAwarePaginator->items());
assertType('ArrayIterator<int, Post>', $lengthAwarePaginator->getIterator());

$lengthAwarePaginator->each(function ($post) {
    assertType('Post', $post);
});

foreach ($lengthAwarePaginator as $post) {
    assertType('Post', $post);
}

/** @var CursorPaginator<int, Post> $cursorPaginator */
$cursorPaginator = new CursorPaginator($items, 1);

assertType('array<int, Post>', $cursorPaginator->items());
assertType('ArrayIterator<int, Post>', $cursorPaginator->getIterator());

assertType('string|null', $cursorPaginator->fragment());
assertType('Hypervel\Pagination\CursorPaginator<int, Post>', $cursorPaginator->fragment('foo'));
assertType('Hypervel\Pagination\CursorPaginator<int, Post>', $cursorPaginator->loadMorph('parentable', [Post::class => ['comments' => function ($relation) {
    assertType('Hypervel\Database\Eloquent\Relations\Relation<*, *, *>', $relation);
}]]));
assertType('Hypervel\Pagination\CursorPaginator<int, Post>', $cursorPaginator->loadMorphCount('parentable', [Post::class => ['comments' => function ($query) {
    assertType('Hypervel\Database\Eloquent\Builder<*>', $query);
}]]));
assertType('Hypervel\Pagination\CursorPaginator<int, Post>', $cursorPaginator->loadMorph('parentable', [Post::class => 'comments']));
assertType('Hypervel\Pagination\CursorPaginator<int, Post>', $cursorPaginator->loadMorphCount('parentable', [Post::class => 'comments']));

foreach (['post', 2] as $alias) {
    assertType('Hypervel\Pagination\CursorPaginator<int, Post>', $cursorPaginator->loadMorph('parentable', [$alias => ['comments']]));
    assertType('Hypervel\Pagination\CursorPaginator<int, Post>', $cursorPaginator->loadMorphCount('parentable', [$alias => ['comments' => []]]));
}

$cursorPaginator->each(function ($post) {
    assertType('Post', $post);
});

foreach ($cursorPaginator as $post) {
    assertType('Post', $post);
}

$cursorPaginatorWithReplacedCollection = clone $cursorPaginator;
$cursorPaginatorWithReplacedCollection->setCollection(new Collection([
    'first' => ['id' => 1],
]));

assertType('Hypervel\Pagination\CursorPaginator<string, array{id: 1}>', $cursorPaginatorWithReplacedCollection);

$throughPaginator = clone $cursorPaginator;
$throughPaginator->through(function ($post, $key): array {
    assertType('int', $key);
    assertType('Post', $post);

    return [
        'id' => $key,
        'post' => $post,
    ];
});

assertType('Hypervel\Pagination\CursorPaginator<int, array{id: int, post: Post}>', $throughPaginator);
