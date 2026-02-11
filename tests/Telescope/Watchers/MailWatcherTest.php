<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Watchers;

use Hypervel\Mail\Events\MessageSent;
use Hypervel\Mail\SentMessage;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Watchers\MailWatcher;
use Hypervel\Tests\Telescope\FeatureTestCase;
use Mockery as m;
use Hypervel\Contracts\Event\Dispatcher;

/**
 * @internal
 * @coversNothing
 */
class MailWatcherTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->get('config')
            ->set('telescope.watchers', [
                MailWatcher::class => true,
            ]);

        $this->startTelescope();
    }

    public function testMailWatcherRegistersValidHtml()
    {
        $message = $this->mockSentMessage([
            'getBody' => '<!DOCTYPE html body',
            'getFrom' => ['from_address'],
            'getReplyTo' => ['reply_to_address'],
            'getTo' => ['to_address'],
            'getCc' => ['cc_address'],
            'getBcc' => ['bcc_address'],
            'getSubject' => 'subject',
            'toString' => 'raw',
        ]);

        $event = new MessageSent($message);

        $this->app->get(Dispatcher::class)
            ->dispatch($event);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::MAIL, $entry->type);
        $this->assertSame('<!DOCTYPE html body', $entry->content['html']);
        $this->assertSame('from_address', $entry->content['from'][0]);
        $this->assertSame('reply_to_address', $entry->content['replyTo'][0]);
        $this->assertSame('to_address', $entry->content['to'][0]);
        $this->assertSame('cc_address', $entry->content['cc'][0]);
        $this->assertSame('bcc_address', $entry->content['bcc'][0]);
        $this->assertSame('subject', $entry->content['subject']);
        $this->assertSame('raw', $entry->content['raw']);
    }

    protected function mockSentMessage(array $data): SentMessage
    {
        $originalMessage = m::mock('originalMessage');
        foreach ($data as $key => $value) {
            $originalMessage->shouldReceive($key)
                ->andReturn($value);
        }

        $message = m::mock(SentMessage::class);
        $message->shouldReceive('getOriginalMessage')
            ->andReturn($originalMessage);
        $message->shouldReceive('getBody')
            ->andReturn('body');

        return $message;
    }
}
