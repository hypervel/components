<?php

declare(strict_types=1);

namespace Hypervel\Types\Head;

use Hypervel\Head\Facades\Head;
use Hypervel\Head\Facades\Schema;
use Hypervel\Head\HeadBuilder;
use Hypervel\Head\HeadManager;
use Hypervel\Head\Schema\Article;
use Hypervel\Head\Schema\SchemaFactory;
use Hypervel\Head\Schema\SchemaObject;

use function PHPStan\Testing\assertType;

assertType(Article::class, Schema::article());

Head::schema(function (SchemaFactory $schema): Article {
    assertType(Article::class, $schema->article());

    return $schema->article()->headline('Article');
});

assertType(CustomHeadSchema::class, Schema::make(CustomHeadSchema::class));

Head::defaults(function ($head): void {
    assertType(HeadBuilder::class, $head);
});

assertType(HeadManager::class, Head::when(true, fn ($head): HeadManager => $head->title('Page'))->description('Description'));

// @phpstan-ignore argument.type
Head::resolveErrorsUsing(fn (string $head): string => $head);

class CustomHeadSchema extends SchemaObject
{
}
