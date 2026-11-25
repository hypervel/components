<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Fixtures;

class LegacyPost extends Models\BlogPost
{
    protected ?string $table = 'blog_posts';
}
