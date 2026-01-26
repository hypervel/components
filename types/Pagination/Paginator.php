<?php

use Hypervel\Pagination\CursorPaginator;
use Hypervel\Pagination\LengthAwarePaginator;
use Hypervel\Pagination\Paginator;

use function PHPStan\Testing\assertType;

$items = [new Post(), new Post(), new Post()];

/** @var Paginator<int, Post> $paginator */
$paginator = new Paginator($items, 1, 1);

assertType('array<int, Post>', $paginator->items());
assertType('Traversable<int, Post>', $paginator->getIterator());

$paginator->each(function ($post) {
    assertType('Post', $post);
});

foreach ($paginator as $post) {
    assertType('Post', $post);
}

/** @var LengthAwarePaginator<int, Post> $lengthAwarePaginator */
$lengthAwarePaginator = new LengthAwarePaginator($items, 1, 1);

assertType('array<int, Post>', $lengthAwarePaginator->items());
assertType('Traversable<int, Post>', $lengthAwarePaginator->getIterator());

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

$cursorPaginator->each(function ($post) {
    assertType('Post', $post);
});

foreach ($cursorPaginator as $post) {
    assertType('Post', $post);
}

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
