<?php

declare(strict_types=1);

namespace Hypervel\Head\Schema;

use DateTimeInterface;
use Hypervel\Head\Enums\EventAttendanceMode;
use Hypervel\Head\Enums\EventStatus;
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
     * Set the event attendance mode.
     */
    public function eventAttendanceMode(EventAttendanceMode $mode): static
    {
        return $this->set('eventAttendanceMode', $mode->url());
    }

    /**
     * Set the event status.
     */
    public function eventStatus(EventStatus $status): static
    {
        return $this->set('eventStatus', $status->url());
    }

    /**
     * Set the event images.
     *
     * @param array<int, string>|string $image
     */
    public function image(string|array $image): static
    {
        return $this->set('image', $image);
    }

    /**
     * Set the event location.
     *
     * Google requires an address on a physical location, so this takes a Place (or a VirtualLocation) rather than a bare venue name.
     */
    public function location(SchemaObject $location): static
    {
        return $this->set('location', $location);
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
     * Set the event performers.
     *
     * @param array<int, Organization|Person>|Organization|Person $performer
     */
    public function performer(Organization|Person|array $performer): static
    {
        return $this->set('performer', $performer);
    }

    /**
     * Set the event start date.
     */
    public function startDate(DateTimeInterface|string $startDate): static
    {
        return $this->date('startDate', $startDate);
    }
}
