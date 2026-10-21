<?php

declare(strict_types=1);

namespace Hypervel\Head\Schema;

use DateTimeInterface;
use Hypervel\Head\SchemaType;

#[SchemaType('Article')]
class Article extends SchemaObject
{
    /**
     * Set the article headline.
     */
    public function headline(string $headline): static
    {
        return $this->set('headline', $headline);
    }

    /**
     * Set the article description.
     */
    public function description(string $description): static
    {
        return $this->set('description', $description);
    }

    /**
     * Set the article author.
     */
    public function author(Person|Organization $author): static
    {
        return $this->set('author', $author);
    }

    /**
     * Set the publication date.
     */
    public function publishedAt(DateTimeInterface|string $date): static
    {
        return $this->date('datePublished', $date);
    }

    /**
     * Set the modification date.
     */
    public function modifiedAt(DateTimeInterface|string $date): static
    {
        return $this->date('dateModified', $date);
    }

    /**
     * Set the article images.
     *
     * @param array<int, string>|string $image
     */
    public function image(string|array $image): static
    {
        return $this->set('image', $image);
    }
}
