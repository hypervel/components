<?php

declare(strict_types=1);

namespace Hypervel\Tests\Head\Feature\Schema;

use DateTimeImmutable;
use Hypervel\Head\Enums\EventAttendanceMode;
use Hypervel\Head\Enums\EventStatus;
use Hypervel\Head\Enums\OfferAvailability;
use Hypervel\Head\Facades\Head;
use Hypervel\Head\Facades\Schema;
use Hypervel\Head\Schema\Place;
use Hypervel\Tests\Head\TestCase;

class EventSchemaTest extends TestCase
{
    public function testRendersAnEventWithThePropertiesRequiredForRichResults(): void
    {
        Head::schema(
            Schema::event()
                ->name('Laracon US')
                ->startDate('2026-08-20T09:00:00-05:00')
                ->endDate(new DateTimeImmutable('2026-08-21T17:00:00-05:00'))
                ->location(
                    Schema::place()
                        ->name('Music City Center')
                        ->address('201 Rep. John Lewis Way S, Nashville, TN')
                )
        );

        $html = Head::toHtml();
        $this->assertStringContainsString('"@type":"Event"', $html);
        $this->assertStringContainsString('"name":"Laracon US"', $html);
        $this->assertStringContainsString('"startDate":"2026-08-20T09:00:00-05:00"', $html);
        $this->assertStringContainsString('"endDate":"2026-08-21T17:00:00-05:00"', $html);
        $this->assertStringContainsString('"location":{"@type":"Place","name":"Music City Center","address":"201 Rep. John Lewis Way S, Nashville, TN"}', $html);
    }

    public function testTreatsAStringLocationAsTheNameOfAPlace(): void
    {
        $event = Schema::event()->location('Music City Center');

        $this->assertSame(['@type' => 'Place', 'name' => 'Music City Center'], $event->toArray()['location']);
    }

    public function testSetsEventStatusAndAttendanceModeFromSchemaOrgEnumValues(): void
    {
        $event = Schema::event()
            ->eventStatus(EventStatus::Scheduled)
            ->eventAttendanceMode(EventAttendanceMode::Offline);

        $this->assertSame([
            '@type' => 'Event',
            'eventStatus' => 'https://schema.org/EventScheduled',
            'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
        ], $event->toArray());
    }

    public function testSetsEventImagesPerformersOrganizerAndOffers(): void
    {
        $event = Schema::event()
            ->image(['https://example.com/hero.jpg', 'https://example.com/stage.jpg'])
            ->performer([Schema::person()->name('Taylor Otwell'), Schema::organization()->name('Hypervel')])
            ->organizer(Schema::organization()->name('Hypervel'))
            ->offers(
                Schema::offer()
                    ->price(499)
                    ->currency('USD')
                    ->availability(OfferAvailability::InStock)
            );

        $this->assertSame([
            '@type' => 'Event',
            'image' => ['https://example.com/hero.jpg', 'https://example.com/stage.jpg'],
            'performer' => [
                ['@type' => 'Person', 'name' => 'Taylor Otwell'],
                ['@type' => 'Organization', 'name' => 'Hypervel'],
            ],
            'organizer' => ['@type' => 'Organization', 'name' => 'Hypervel'],
            'offers' => ['@type' => 'Offer', 'price' => 499, 'priceCurrency' => 'USD', 'availability' => 'https://schema.org/InStock'],
        ], $event->toArray());
    }

    public function testRegistersPlaceAsAFirstClassFactoryMethod(): void
    {
        $this->assertInstanceOf(Place::class, Schema::place());
        $this->assertSame(['@type' => 'Place', 'name' => 'Venue', 'url' => 'https://example.com/venue'], Schema::place()->name('Venue')->url('https://example.com/venue')->toArray());
    }

    public function testIncludesAllSchemaOrgEventStatusAndAttendanceModeValues(): void
    {
        $this->assertSame('https://schema.org/EventCancelled', EventStatus::Cancelled->url());
        $this->assertSame('https://schema.org/EventMovedOnline', EventStatus::MovedOnline->url());
        $this->assertSame('https://schema.org/MixedEventAttendanceMode', EventAttendanceMode::Mixed->url());
    }
}
