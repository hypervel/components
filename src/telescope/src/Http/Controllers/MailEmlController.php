<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Http\Controllers;

use Hypervel\Http\Response;
use Hypervel\Telescope\Contracts\EntriesRepository;

class MailEmlController
{
    /**
     * Download the Eml content of the email.
     */
    public function show(EntriesRepository $storage, string $id): Response
    {
        return response($storage->find($id)->content['raw'], 200, [
            'Content-Type' => 'message/rfc822',
            'Content-Disposition' => 'attachment; filename="mail-' . $id . '.eml"',
        ]);
    }
}
