<?php

declare(strict_types=1);

namespace Hypervel\Head\Schema;

use DateTimeInterface;
use Hypervel\Head\SchemaType;

#[SchemaType('Event')]
class Event extends SchemaObject
{
    /**
     * Set the event description.
     */
    public function description(string $description): static
    {
        return $this->set('description', $description);
    }

    /**
     * Set the time admission begins.
     */
    public function doorTime(DateTimeInterface|string $doorTime): static
    {
        return $this->date('doorTime', $doorTime);
    }

    /**
     * Set the event end date.
     */
    public function endDate(DateTimeInterface|string $endDate): static
    {
        return $this->date('endDate', $endDate);
    }

    /**
     * Set the event name.
     */
    public function name(string $name): static
    {
        return $this->set('name', $name);
    }

    /**
     * Set the event offers.
     *
     * @param array<int, Offer>|Offer $offers
     */
    public function offers(Offer|array $offers): static
    {
        return $this->set('offers', $offers);
    }

    /**
     * Set the event organizer.
     */
    public function organizer(Organization|Person $organizer): static
    {
        return $this->set('organizer', $organizer);
    }

    /**
     * Set the event start date.
     */
    public function startDate(DateTimeInterface|string $startDate): static
    {
        return $this->date('startDate', $startDate);
    }
}
