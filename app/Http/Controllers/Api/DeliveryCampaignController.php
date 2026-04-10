<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeliveryCampaign;
use App\Services\DeliveryCampaignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeliveryCampaignController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $campaigns = DeliveryCampaign::with(['user', 'projectMailSource'])
            ->latest()
            ->paginate(20);

        $campaigns->getCollection()->transform(function ($campaign) {
            return [
                'id'              => $campaign->id,
                'project_mail_id' => $campaign->project_mail_id,
                'project_title'   => $campaign->projectMailSource?->title,
                'subject'         => $campaign->subject,
                'sent_at'         => $campaign->sent_at?->toIso8601String(),
                'sent_by'         => $campaign->user?->name,
                'total_count'     => $campaign->total_count,
                'success_count'   => $campaign->success_count,
                'failed_count'    => $campaign->failed_count,
            ];
        });

        return response()->json($campaigns);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_mail_id' => 'nullable|exists:project_mail_sources,id',
            'subject'         => 'required|string|max:500',
            'body'            => 'required|string',
        ]);

        // TODO: DeliveryCampaignService を呼び出して一括送信を実行する
        $service = new DeliveryCampaignService(
            tenantId: auth()->user()->tenant_id,
            userId:   auth()->id(),
        );
        $campaign = $service->send($validated);

        return response()->json($campaign, 201);
    }

    public function show(int $id): JsonResponse
    {
        $campaign = DeliveryCampaign::with([
            'user',
            'projectMailSource',
            'sendHistories' => function ($query) {
                $query->with('replyEmail')->orderBy('id');
            },
        ])->findOrFail($id);

        return response()->json([
            'id'              => $campaign->id,
            'project_mail_id' => $campaign->project_mail_id,
            'project_title'   => $campaign->projectMailSource?->title,
            'subject'         => $campaign->subject,
            'body'            => $campaign->body,
            'sent_at'         => $campaign->sent_at?->toIso8601String(),
            'sent_by'         => $campaign->user?->name,
            'total_count'     => $campaign->total_count,
            'success_count'   => $campaign->success_count,
            'failed_count'    => $campaign->failed_count,
            'histories'       => $campaign->sendHistories->map(function ($h) {
                return [
                    'id'                  => $h->id,
                    'email'               => $h->email,
                    'name'                => $h->name,
                    'status'              => $h->status,
                    'replied_at'          => $h->replied_at?->toIso8601String(),
                    'reply_subject'       => $h->replyEmail?->subject,
                    'reply_received_at'   => $h->replyEmail?->received_at?->toIso8601String(),
                ];
            }),
        ]);
    }
}
