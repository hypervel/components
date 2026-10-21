<?php

declare(strict_types=1);

namespace Hypervel\Tests\Head\Fixtures;

use DateTimeInterface;
use Hypervel\Head\Schema\SchemaObject;
use Hypervel\Head\SchemaType;

#[SchemaType('JobPosting')]
class JobPosting extends SchemaObject
{
    /**
     * Set the job title.
     */
    public function title(string $title): static
    {
        return $this->set('title', $title);
    }

    /**
     * Set the posting date.
     */
    public function datePosted(DateTimeInterface|string $date): static
    {
        return $this->date('datePosted', $date);
    }
}
