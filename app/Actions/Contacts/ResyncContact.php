<?php

namespace App\Actions\Contacts;

use App\Jobs\SyncContactToGoogleJob;
use App\Models\Contact;
use App\Services\Google\GoogleAccount;

/** Reenvia ao Google um contato que deu erro: zera o erro e a tentativa e enfileira de novo. */
class ResyncContact
{
    public function __construct(private readonly GoogleAccount $account) {}

    public function __invoke(Contact $contact): void
    {
        if (! $this->account->isConnected()) {
            return;
        }

        $contact->forceFill(['google_error' => null, 'google_attempted_at' => null])->save();

        SyncContactToGoogleJob::dispatchWhenConnected($contact);
    }
}
