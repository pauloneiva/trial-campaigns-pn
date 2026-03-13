<?php

namespace App\Services;

use App\Jobs\SendCampaignEmail;
use App\Models\Campaign;
use App\Models\CampaignSend;

class CampaignService
{
    /**
     * Dispatch a campaign to all active contacts in its list.
     */
    public function dispatch(Campaign $campaign): void
    {
        $chunkSize = 200;
        $campaign->contactList->contacts()
            ->where('status', 'active')
            ->chunk($chunkSize, function ($contacts) use ($campaign) {
                foreach ($contacts as $contact) {
                    $send = CampaignSend::firstOrCreate(
                        ['campaign_id' => $campaign->id, 'contact_id' => $contact->id],
                        ['status' => 'pending']
                    );

                    if ($send->status === 'pending') {
                        SendCampaignEmail::dispatch($send);
                    }
                }
            });

        $campaign->update(['status' => 'sending']);
    }
}