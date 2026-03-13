<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Services\CampaignService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchCampaign implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly Campaign $campaign
    ) {}

    public function handle(CampaignService $service): void
    {
        $this->campaign->refresh();

        if ($this->campaign->status !== 'draft') {
            return;
        }

        $service->dispatch($this->campaign);
    }
}
