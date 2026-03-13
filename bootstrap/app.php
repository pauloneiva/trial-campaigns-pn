<?php

use App\Jobs\DispatchCampaign;
use App\Models\Campaign;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->call(function () {
            Campaign::where('status', 'draft')
                ->where('scheduled_at', '<=', now())
                ->whereNotNull('scheduled_at')
                ->get()
                ->each(fn($campaign) => DispatchCampaign::dispatch($campaign));
        })->everyMinute();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'campaign.draft' => \App\Http\Middleware\EnsureCampaignIsDraft::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($request->is('api/*')) {
                $status = $e->getStatusCode();
                $message = $e->getMessage() ?: \Symfony\Component\HttpFoundation\Response::$statusTexts[$status] ?? 'Error';

                return response()->json(['message' => $message], $status);
            }
        });
    })->create();
