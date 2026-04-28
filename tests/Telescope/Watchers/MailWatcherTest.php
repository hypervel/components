<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Watchers;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Mail\Events\MessageSent;
use Hypervel\Mail\SentMessage;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Mail;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Watchers\MailWatcher;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Telescope\FeatureTestCase;
use Mockery as m;

#[WithConfig('mail.driver', 'array')]
#[WithConfig('telescope.watchers', [
    MailWatcher::class => true,
])]
class MailWatcherTest extends FeatureTestCase
{
    public function testMailWatcherRegistersEntry()
    {
        Mail::raw('Telescope is amazing!', function ($message) {
            $message->from('from@hypervel.org')
                ->to('to@hypervel.org')
                ->cc(['cc1@hypervel.org', 'cc2@hypervel.org'])
                ->bcc('bcc@hypervel.org')
                ->subject('Check this out!');
        });

        $entry = $this->loadTelescopeEntries()->first();

        $tags = DB::table('telescope_entries_tags')
            ->where('entry_uuid', $entry->getKey())
            ->pluck('tag')
            ->all();

        $this->assertSame(EntryType::MAIL, $entry->type);
        $this->assertEmpty($entry->content['mailable']);
        $this->assertFalse($entry->content['queued']);
        $this->assertSame(['from@hypervel.org'], array_keys($entry->content['from']));
        $this->assertSame(['to@hypervel.org'], array_keys($entry->content['to']));
        $this->assertSame(['cc1@hypervel.org', 'cc2@hypervel.org'], array_keys($entry->content['cc']));
        $this->assertSame(['bcc@hypervel.org'], array_keys($entry->content['bcc']));
        $this->assertSame('Check this out!', $entry->content['subject']);
        $this->assertSame('Telescope is amazing!', $entry->content['html']);
        $this->assertStringContainsString('Telescope is amazing!', $entry->content['raw']);
        $this->assertEmpty($entry->content['replyTo']);
        $this->assertContains('to@hypervel.org', $tags);
        $this->assertContains('bcc@hypervel.org', $tags);
        $this->assertContains('cc1@hypervel.org', $tags);
        $this->assertContains('cc2@hypervel.org', $tags);
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

        $this->app->make(Dispatcher::class)
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
