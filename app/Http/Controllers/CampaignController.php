<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCampaignRequest;
use App\Models\Campaign;
use App\Services\CampaignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CampaignController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min($request->integer('per_page', config('pagination.per_page')), config('pagination.max_per_page'));

        $campaigns = Campaign::withCount([
            'sends as pending_count' => fn($q) => $q->where('status', 'pending'),
            'sends as sent_count'    => fn($q) => $q->where('status', 'sent'),
            'sends as failed_count'  => fn($q) => $q->where('status', 'failed'),
        ])->paginate($perPage);

        $campaigns->getCollection()->transform(function (Campaign $campaign) {
            $campaign->setAttribute('stats', [
                'pending' => $campaign->pending_count,
                'sent'    => $campaign->sent_count,
                'failed'  => $campaign->failed_count,
                'total'   => $campaign->pending_count + $campaign->sent_count + $campaign->failed_count,
            ]);

            return $campaign->makeHidden(['pending_count', 'sent_count', 'failed_count']);
        });

        return response()->json($campaigns);
    }

    public function store(StoreCampaignRequest $request): JsonResponse
    {
        $campaign = Campaign::create($request->validated());

        return response()->json($campaign, 201);
    }

    public function show(Campaign $campaign): JsonResponse
    {
        return response()->json(array_merge($campaign->toArray(), [
            'stats' => $campaign->stats,
        ]));
    }

    public function dispatch(Campaign $campaign, CampaignService $service): JsonResponse
    {
        $service->dispatch($campaign);

        return response()->json(['message' => 'Campaign dispatched successfully.']);
    }
}
