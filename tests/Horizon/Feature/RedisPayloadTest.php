<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Feature;

use Hypervel\Broadcasting\BroadcastEvent;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Horizon\Contracts\Silenced;
use Hypervel\Horizon\JobPayload;
use Hypervel\Mail\Contracts\Mailable;
use Hypervel\Mail\SendQueuedMailable;
use Hypervel\Notifications\SendQueuedNotifications;
use Hypervel\Tests\Horizon\Feature\Fixtures\FakeEvent;
use Hypervel\Tests\Horizon\Feature\Fixtures\FakeEventWithModel;
use Hypervel\Tests\Horizon\Feature\Fixtures\FakeJobWithEloquentCollection;
use Hypervel\Tests\Horizon\Feature\Fixtures\FakeJobWithEloquentModel;
use Hypervel\Tests\Horizon\Feature\Fixtures\FakeJobWithTagsMethod;
use Hypervel\Tests\Horizon\Feature\Fixtures\FakeListener;
use Hypervel\Tests\Horizon\Feature\Fixtures\FakeListenerSilenced;
use Hypervel\Tests\Horizon\Feature\Fixtures\FakeListenerWithDynamicTags;
use Hypervel\Tests\Horizon\Feature\Fixtures\FakeListenerWithProperties;
use Hypervel\Tests\Horizon\Feature\Fixtures\FakeModel;
use Hypervel\Tests\Horizon\Feature\Fixtures\FakeSilencedJob;
use Hypervel\Tests\Horizon\Feature\Fixtures\SilencedMailable;
use Hypervel\Tests\Horizon\IntegrationTestCase;
use Illuminate\Events\CallQueuedListener;
use Mockery;
use StdClass;

/**
 * @internal
 * @coversNothing
 */
class RedisPayloadTest extends IntegrationTestCase
{
    public function testTypeIsCorrectlyDetermined()
    {
        $JobPayload = new JobPayload(json_encode(['id' => 1]));

        $JobPayload->prepare(new BroadcastEvent(new StdClass()));
        $this->assertSame('broadcast', $JobPayload->decoded['type']);

        $JobPayload->prepare(new CallQueuedListener('stdClass', 'method', [new StdClass()]));
        $this->assertSame('event', $JobPayload->decoded['type']);

        $JobPayload->prepare(new SendQueuedMailable(Mockery::mock(Mailable::class)));
        $this->assertSame('mail', $JobPayload->decoded['type']);

        $JobPayload->prepare(new SendQueuedNotifications([], new StdClass(), ['mail']));
        $this->assertSame('notification', $JobPayload->decoded['type']);
    }

    public function testTagsAreCorrectlyDetermined()
    {
        $JobPayload = new JobPayload(json_encode(['id' => 1]));

        $first = new FakeModel();
        $first->id = 1;

        $second = new FakeModel();
        $second->id = 2;

        $JobPayload->prepare(new FakeJobWithEloquentModel($first, $second));
        $this->assertEquals([FakeModel::class . ':1', FakeModel::class . ':2'], $JobPayload->decoded['tags']);
    }

    public function testTagsAreCorrectlyGatheredFromCollections()
    {
        $JobPayload = new JobPayload(json_encode(['id' => 1]));

        $first = new FakeModel();
        $first->id = 1;

        $second = new FakeModel();
        $second->id = 2;

        $JobPayload->prepare(new FakeJobWithEloquentCollection(new EloquentCollection([$first, $second])));
        $this->assertEquals([FakeModel::class . ':1', FakeModel::class . ':2'], $JobPayload->decoded['tags']);
    }

    public function testTagsAreCorrectlyExtractedForListeners()
    {
        $JobPayload = new JobPayload(json_encode(['id' => 1]));

        $job = new CallQueuedListener(FakeListener::class, 'handle', [new FakeEvent()]);

        $JobPayload->prepare($job);

        $this->assertEquals([
            'listenerTag1', 'listenerTag2', 'eventTag1', 'eventTag2',
        ], $JobPayload->decoded['tags']);
    }

    public function testTagsAreCorrectlyExtractedForListenersWithDynamicEventInformation()
    {
        $JobPayload = new JobPayload(json_encode(['id' => 1]));

        $job = new CallQueuedListener(FakeListenerWithDynamicTags::class, 'handle', [new FakeEvent()]);

        $JobPayload->prepare($job);

        $this->assertEquals([
            'listenerTag1', FakeEvent::class, 'eventTag1', 'eventTag2',
        ], $JobPayload->decoded['tags']);
    }

    public function testTagsAreCorrectlyDeterminedForListeners()
    {
        $JobPayload = new JobPayload(json_encode(['id' => 1]));

        $job = new CallQueuedListener(FakeListenerWithProperties::class, 'handle', [new FakeEventWithModel(42)]);

        $JobPayload->prepare($job);

        $this->assertEquals([FakeModel::class . ':42'], $JobPayload->decoded['tags']);
    }

    public function testListenerAndEventTagsCanMergeAutoTagEvents()
    {
        $JobPayload = new JobPayload(json_encode(['id' => 1]));

        $job = new CallQueuedListener(FakeListener::class, 'handle', [new FakeEventWithModel(5)]);

        $JobPayload->prepare($job);

        $this->assertEquals([
            'listenerTag1', 'listenerTag2', FakeModel::class . ':5',
        ], $JobPayload->decoded['tags']);
    }

    public function testTagsAreAddedToExisting()
    {
        $JobPayload = new JobPayload(json_encode(['id' => 1, 'tags' => ['mytag']]));

        $job = new CallQueuedListener(FakeListenerWithProperties::class, 'handle', [new FakeEventWithModel(42)]);

        $JobPayload->prepare($job);

        $this->assertEquals(['mytag', FakeModel::class . ':42'], $JobPayload->decoded['tags']);
    }

    public function testJobsCanHaveTagsMethodToOverrideAutoTagging()
    {
        $JobPayload = new JobPayload(json_encode(['id' => 1]));

        $JobPayload->prepare(new FakeJobWithTagsMethod());
        $this->assertEquals(['first', 'second'], $JobPayload->decoded['tags']);
    }

    public function testItDeterminesIfJobIsSilencedCorrectly()
    {
        $JobPayload = new JobPayload(json_encode(['id' => 1]));

        $JobPayload->prepare(new BroadcastEvent(new class implements Silenced {}));
        $this->assertTrue($JobPayload->isSilenced());

        $JobPayload->prepare(new CallQueuedListener(FakeListenerSilenced::class, 'handle', [new FakeEventWithModel(42)]));
        $this->assertTrue($JobPayload->isSilenced());

        $JobPayload->prepare(new SendQueuedNotifications([], new class implements Silenced {}, ['mail']));
        $this->assertTrue($JobPayload->isSilenced());

        $JobPayload->prepare(new FakeSilencedJob());
        $this->assertTrue($JobPayload->isSilenced());

        $JobPayload->prepare(new BroadcastEvent(new class {}));
        $this->assertFalse($JobPayload->isSilenced());
    }

    public function testItDeterminesIfJobIsSilencedCorrectlyForMailable()
    {
        $JobPayload = new JobPayload(json_encode(['id' => 1]));

        $mailableMock = Mockery::mock(SilencedMailable::class);
        config(['horizon.silenced' => [get_class($mailableMock)]]);
        $JobPayload->prepare(new SendQueuedMailable($mailableMock));
        $this->assertTrue($JobPayload->isSilenced());
    }
}
