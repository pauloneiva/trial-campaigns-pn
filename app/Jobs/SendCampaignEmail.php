<?php

namespace App\Jobs;

use App\Models\CampaignSend;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendCampaignEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 60, 90];

    public function __construct(
        private readonly CampaignSend $send
    ) {}

    public function handle(): void
    {
        // Refresh from DB — guard against retry after partial execution
        $this->send->refresh();

        if ($this->send->status === 'sent') {
            // check whether this was the last send so the campaign can be marked as 'sent'.
            $this->markCampaignSentIfComplete();
            return;
        }

        $this->send->loadMissing(['contact', 'campaign']);

        $this->sendEmail(
            $this->send->contact->email,
            $this->send->campaign->subject,
            $this->send->campaign->body,
        );

        $this->send->update(['status' => 'sent']);
        $this->markCampaignSentIfComplete();
    }

    public function failed(\Throwable $e): void
    {
        $this->send->update([
            'status'        => 'failed',
            'error_message' => $e->getMessage(),
        ]);

        Log::error('Campaign send failed after all retries', [
            'send_id' => $this->send->id,
            'error'   => $e->getMessage(),
        ]);

        $this->markCampaignSentIfComplete();
    }

    private function markCampaignSentIfComplete(): void
    {
        $this->send->loadMissing('campaign');

        $pendingCount = $this->send->campaign->sends()
            ->where('status', 'pending')
            ->count();

        if ($pendingCount === 0) {
            $this->send->campaign->update(['status' => 'sent']);
        }
    }

    private function sendEmail(string $to, string $subject, string $body): void
    {
        Log::info("Sending email to {$to}: {$subject}");
    }
}