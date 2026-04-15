<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeliveryCampaign;
use App\Services\DeliveryCampaignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DeliveryCampaignController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $search    = $request->input('search');
        $sendType  = $request->input('send_type');
        $dateFrom  = $request->input('date_from');
        $dateTo    = $request->input('date_to');
        $userId    = $request->input('user_id');
        $perPage   = $request->integer('per_page', 20);

        $allowedSortBy = ['sent_at', 'subject', 'sent_by', 'project_title'];
        $sortBy  = in_array($request->input('sort_by'), $allowedSortBy) ? $request->input('sort_by') : 'sent_at';
        $sortDir = $request->input('sort_dir') === 'asc' ? 'asc' : 'desc';

        $query = DeliveryCampaign::with(['user', 'projectMailSource', 'engineerMailSource']);

        match ($sortBy) {
            'sent_at'       => $query->orderBy('sent_at', $sortDir),
            'subject'       => $query->orderBy('subject', $sortDir),
            'sent_by'       => $query->orderByRaw("(SELECT name FROM users WHERE users.id = delivery_campaigns.user_id) {$sortDir} NULLS LAST"),
            'project_title' => $query->orderByRaw("(SELECT title FROM project_mail_sources WHERE project_mail_sources.id = delivery_campaigns.project_mail_id) {$sortDir} NULLS LAST"),
        };

        if ($sendType) {
            $query->where('send_type', $sendType);
        }

        if ($userId) {
            $query->where('user_id', $userId);
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
                'replied_count'            => $campaign->replied_count,
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

        $service = new DeliveryCampaignService(
            tenantId:   auth()->user()->tenant_id,
            userId:     auth()->id(),
            senderName: auth()->user()->name ?? '',
        );

        // キャンペーン作成（即座に返す）
        $campaign = $service->createCampaign($validated);

        // レスポンス返却後にバックグラウンドで送信
        register_shutdown_function(function () use ($service, $campaign) {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            set_time_limit(0);
            ignore_user_abort(true);
            $service->sendCampaign($campaign);
        });

        return response()->json([
            'id'          => $campaign->id,
            'total_count' => $campaign->total_count,
        ], 201);
    }

    public function progress(int $id): JsonResponse
    {
        $campaign = DeliveryCampaign::findOrFail($id);

        return response()->json([
            'id'            => $campaign->id,
            'total_count'   => $campaign->total_count,
            'success_count' => $campaign->success_count,
            'failed_count'  => $campaign->failed_count,
            'is_sending'    => Cache::has("campaign_sending_{$id}"),
        ]);
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
                    'reply_body_snippet'   => $h->replyEmail ? mb_substr(strip_tags($h->replyEmail->body_text ?? $h->replyEmail->body_html ?? ''), 0, 300) : null,
                ];
            }),
        ]);
    }
}
