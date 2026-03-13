<?php

namespace App\Console;

use App\Jobs\DispatchCampaign;
use App\Models\Campaign;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        //TO-DO: remove
        /*
        $schedule->call(function () {
            Campaign::where('status', 'draft')
                ->where('scheduled_at', '<=', now())
                ->whereNotNull('scheduled_at')
                ->get()
                ->each(fn($campaign) => DispatchCampaign::dispatch($campaign));
        })->everyMinute();
        */
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
