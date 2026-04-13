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
        $search    = $request->input('search');
        $sendType  = $request->input('send_type');
        $dateFrom  = $request->input('date_from');
        $dateTo    = $request->input('date_to');
        $perPage   = $request->integer('per_page', 20);

        $query = DeliveryCampaign::with(['user', 'projectMailSource', 'engineerMailSource'])
            ->latest();

        if ($sendType) {
            $query->where('send_type', $sendType);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('subject', 'like', "%{$search}%")
                  ->orWhereHas('projectMailSource', fn($pq) =>
                      $pq->where('title', 'like', "%{$search}%")
                  )
                  ->orWhereHas('sendHistories', fn($sq) =>
                      $sq->where('email', 'like', "%{$search}%")
                         ->orWhere('name', 'like', "%{$search}%")
                  );
            });
        }

        if ($dateFrom) {
            $query->where('sent_at', '>=', $dateFrom . ' 00:00:00');
        }

        if ($dateTo) {
            $query->where('sent_at', '<=', $dateTo . ' 23:59:59');
        }

        $campaigns = $query->paginate($perPage);

        $campaigns->getCollection()->transform(function ($campaign) {
            return [
                'id'                       => $campaign->id,
                'send_type'                => $campaign->send_type,
                'project_mail_id'          => $campaign->project_mail_id,
                'project_title'            => $campaign->projectMailSource?->title,
                'engineer_mail_source_id'  => $campaign->engineer_mail_source_id,
                'engineer_mail_title'      => $campaign->engineerMailSource?->title,
                'subject'                  => $campaign->subject,
                'sent_at'                  => $campaign->sent_at?->toIso8601String(),
                'sent_by'                  => $campaign->user?->name,
                'total_count'              => $campaign->total_count,
                'success_count'            => $campaign->success_count,
                'failed_count'             => $campaign->failed_count,
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
            'engineerMailSource',
            'sendHistories' => function ($query) {
                $query->with(['replyEmail', 'engineer', 'publicProject'])->orderBy('id');
            },
        ])->findOrFail($id);

        return response()->json([
            'id'                      => $campaign->id,
            'send_type'               => $campaign->send_type,
            'project_mail_id'         => $campaign->project_mail_id,
            'project_title'           => $campaign->projectMailSource?->title,
            'engineer_mail_source_id' => $campaign->engineer_mail_source_id,
            'engineer_mail_title'     => $campaign->engineerMailSource?->title,
            'subject'                 => $campaign->subject,
            'body'                    => $campaign->body,
            'sent_at'                 => $campaign->sent_at?->toIso8601String(),
            'sent_by'                 => $campaign->user?->name,
            'total_count'             => $campaign->total_count,
            'success_count'           => $campaign->success_count,
            'failed_count'            => $campaign->failed_count,
            'histories'               => $campaign->sendHistories->map(function ($h) {
                return [
                    'id'                   => $h->id,
                    'email'                => $h->email,
                    'name'                 => $h->name,
                    'status'               => $h->status,
                    'engineer_id'          => $h->engineer_id,
                    'engineer_name'        => $h->engineer?->name,
                    'public_project_id'    => $h->public_project_id,
                    'public_project_title' => $h->publicProject?->title,
                    'replied_at'           => $h->replied_at?->toIso8601String(),
                    'reply_subject'        => $h->replyEmail?->subject,
                    'reply_received_at'    => $h->replyEmail?->received_at?->toIso8601String(),
                ];
            }),
        ]);
    }
}
