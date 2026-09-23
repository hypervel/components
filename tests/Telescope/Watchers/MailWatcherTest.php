<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Watchers;

use Hypervel\Mail\Message;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Mail;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Watchers\MailWatcher;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Telescope\FeatureTestCase;

#[WithConfig('mail.default', 'array')]
#[WithConfig('telescope.watchers', [
    MailWatcher::class => true,
])]
class MailWatcherTest extends FeatureTestCase
{
    public function testMailWatcherRegistersEntry(): void
    {
        Mail::raw('Telescope is amazing!', function (Message $message): void {
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
}
