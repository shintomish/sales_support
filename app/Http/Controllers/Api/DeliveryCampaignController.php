<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeliveryCampaign;
use App\Models\DeliverySendHistory;
use App\Services\DeliveryCampaignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DeliveryCampaignController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $search    = $request->input('search');
        $sendType     = $request->input('send_type');
        $dateFrom     = $request->input('date_from');
        $dateTo       = $request->input('date_to');
        $userId       = $request->input('user_id');
        $deliveryType = $request->input('delivery_type');
        $perPage   = $request->integer('per_page', 20);

        $allowedSortBy = ['sent_at', 'subject', 'sent_by', 'project_title'];
        $sortBy  = in_array($request->input('sort_by'), $allowedSortBy) ? $request->input('sort_by') : 'sent_at';
        $sortDir = $request->input('sort_dir') === 'asc' ? 'asc' : 'desc';

        $query = DeliveryCampaign::with(['user', 'projectMailSource', 'engineerMailSource.email']);

        match ($sortBy) {
            'sent_at'       => $query->orderBy('sent_at', $sortDir),
            'subject'       => $query->orderBy('subject', $sortDir),
            'sent_by'       => $query->orderByRaw("(SELECT name FROM users WHERE users.id = delivery_campaigns.user_id) {$sortDir} NULLS LAST"),
            'project_title' => $query->orderByRaw("(SELECT title FROM project_mail_sources WHERE project_mail_sources.id = delivery_campaigns.project_mail_id) {$sortDir} NULLS LAST"),
        };

        if ($sendType) {
            $query->where('send_type', $sendType);
        }

        if ($request->boolean('exclude_proposals')) {
            $query->whereNotIn('send_type', ['proposal', 'engineer_proposal', 'matching_proposal']);
        }

        if ($userId) {
            $query->where('user_id', $userId);
        }

        if ($deliveryType === 'engineer') {
            $query->whereNotNull('engineer_mail_source_id');
        } elseif ($deliveryType === 'project') {
            $query->whereNull('engineer_mail_source_id');
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
                'engineer_mail_title'      => $campaign->engineerMailSource?->name
                                              ? ($campaign->engineerMailSource->name . '｜' . ($campaign->engineerMailSource->email?->subject ?? ''))
                                              : null,
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
            'project_mail_id'         => 'nullable|exists:project_mail_sources,id',
            'engineer_mail_source_id' => 'nullable|exists:engineer_mail_sources,id',
            'subject'                 => 'required|string|max:500',
            'body'                    => 'required|string',
            'attachments'             => 'nullable|array',
            'attachments.*'           => 'file|max:10240',
        ]);

        // 未置換プレースホルダ検出（<%Name%> は配信時にバックエンドで置換するため除外）
        $unresolved = $this->findUnresolvedPlaceholders($validated['subject'] . "\n" . $validated['body']);
        if (!empty($unresolved)) {
            return response()->json([
                'message'      => '未置換のプレースホルダがあります: ' . implode(' / ', $unresolved)
                                  . ' 。メール署名設定を確認してください。',
                'placeholders' => $unresolved,
            ], 422);
        }

        // アップロードファイルを一時ディレクトリに保存
        $attachmentPaths = [];
        if ($request->hasFile('attachments')) {
            $tempDir = storage_path('app/temp/campaigns/' . uniqid('camp_', true));
            mkdir($tempDir, 0755, true);
            foreach ($request->file('attachments') as $file) {
                $dest = $tempDir . '/' . $file->getClientOriginalName();
                $file->move($tempDir, $file->getClientOriginalName());
                $attachmentPaths[] = $dest;
            }
        }

        $service = new DeliveryCampaignService(
            tenantId:   auth()->user()->tenant_id,
            userId:     auth()->id(),
            senderName: auth()->user()->name ?? '',
        );

        // キャンペーン作成（即座に返す）
        $campaign = $service->createCampaign($validated);

        // レスポンス返却後にバックグラウンドで送信
        register_shutdown_function(function () use ($service, $campaign, $attachmentPaths) {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            set_time_limit(0);
            ignore_user_abort(true);
            $service->sendCampaign($campaign, $attachmentPaths);
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
                    'reply_body_text'      => $h->replyEmail?->body_text,
                    'reply_from'           => $h->replyEmail?->from_address,
                    'reply_from_name'      => $h->replyEmail?->from_name,
                ];
            }),
        ]);
    }

    /**
     * 提案スレッド一覧
     * delivery_campaigns を project_mail_id / engineer_mail_source_id でグループ化し、
     * スレッド単位で最新アクティビティ・返信有無を返す。
     */
    public function proposalThreads(Request $request): JsonResponse
    {
        $status  = $request->input('status');
        $search  = $request->input('search');
        $type    = $request->input('type'); // 'project' | 'engineer'
        $userId  = $request->input('user_id');
        $perPage = $request->integer('per_page', 20);

        // ── スレッド単位の集計サブクエリ ──
        // project_mail_id / engineer_mail_source_id でグループ化し、
        // 各グループの最新 sent_at、合計件数、返信有無を取得
        $threadsQuery = DeliveryCampaign::query()
            ->whereIn('send_type', ['proposal', 'engineer_proposal', 'delivery'])
            ->where(function ($q) {
                $q->whereNotNull('project_mail_id')
                  ->orWhereNotNull('engineer_mail_source_id');
            })
            ->select([
                DB::raw("COALESCE(project_mail_id::text, 'e_' || engineer_mail_source_id::text) as thread_key"),
                DB::raw('MIN(id) as first_campaign_id'),
                'project_mail_id',
                'engineer_mail_source_id',
                DB::raw('MAX(sent_at) as latest_sent_at'),
            ])
            ->groupBy('project_mail_id', 'engineer_mail_source_id');

        // user_id フィルタ
        if ($userId) {
            $threadsQuery->where('user_id', $userId);
        }

        // type フィルタ
        if ($type === 'project') {
            $threadsQuery->whereNotNull('project_mail_id')->whereNull('engineer_mail_source_id');
        } elseif ($type === 'engineer') {
            $threadsQuery->whereNotNull('engineer_mail_source_id');
        }

        // スレッドサブクエリをベースに完全なクエリを組み立て
        $threads = DB::table(DB::raw("({$threadsQuery->toSql()}) as threads"))
            ->mergeBindings($threadsQuery->getQuery())
            ->leftJoin('project_mail_sources', 'project_mail_sources.id', '=', 'threads.project_mail_id')
            ->leftJoin('engineer_mail_sources', 'engineer_mail_sources.id', '=', 'threads.engineer_mail_source_id')
            ->select([
                'threads.thread_key',
                'threads.first_campaign_id',
                'threads.project_mail_id',
                'threads.engineer_mail_source_id',
                'threads.latest_sent_at',
                DB::raw("CASE WHEN threads.project_mail_id IS NOT NULL THEN 'project' ELSE 'engineer' END as type"),
                DB::raw('COALESCE(threads.project_mail_id, threads.engineer_mail_source_id) as source_id'),
                'project_mail_sources.customer_name',
                'project_mail_sources.title as project_title',
                'project_mail_sources.status as project_status',
                'engineer_mail_sources.name as engineer_name',
                'engineer_mail_sources.status as engineer_status',
            ]);

        // status フィルタ
        if ($status) {
            $threads->where(function ($q) use ($status) {
                $q->where('project_mail_sources.status', $status)
                  ->orWhere('engineer_mail_sources.status', $status);
            });
        }

        // search フィルタ
        if ($search) {
            $threads->where(function ($q) use ($search) {
                $q->where('project_mail_sources.customer_name', 'ilike', "%{$search}%")
                  ->orWhere('project_mail_sources.title', 'ilike', "%{$search}%")
                  ->orWhere('engineer_mail_sources.name', 'ilike', "%{$search}%");
            });
        }

        // ソート: 最新送信日時の降順
        $threads->orderByDesc('threads.latest_sent_at');

        // ページネーション
        $paginated = $threads->paginate($perPage);

        // ── 各スレッドに対して送信/返信情報を付加 ──
        $threadItems = collect($paginated->items());
        $projectMailIds  = $threadItems->pluck('project_mail_id')->filter()->unique()->values()->all();
        $engineerMailIds = $threadItems->pluck('engineer_mail_source_id')->filter()->unique()->values()->all();

        // 各スレッドに紐づくキャンペーンIDを一括取得
        $campaignsByThread = DeliveryCampaign::query()
            ->whereIn('send_type', ['proposal', 'engineer_proposal', 'delivery'])
            ->where(function ($q) use ($projectMailIds, $engineerMailIds) {
                if ($projectMailIds) {
                    $q->orWhereIn('project_mail_id', $projectMailIds);
                }
                if ($engineerMailIds) {
                    $q->orWhereIn('engineer_mail_source_id', $engineerMailIds);
                }
            })
            ->when($userId, fn($q) => $q->where('user_id', $userId))
            ->select('id', 'project_mail_id', 'engineer_mail_source_id', 'sent_at', 'success_count')
            ->get()
            ->groupBy(function ($c) {
                return $c->project_mail_id
                    ? (string) $c->project_mail_id
                    : 'e_' . $c->engineer_mail_source_id;
            });

        // 全キャンペーンIDを収集して送信履歴を一括取得
        $allCampaignIds = $campaignsByThread->flatten()->pluck('id')->unique()->values()->all();

        $sendHistories = DeliverySendHistory::query()
            ->whereIn('campaign_id', $allCampaignIds)
            ->select('id', 'campaign_id', 'email', 'name', 'status', 'replied_at', 'reply_email_id')
            ->get()
            ->groupBy('campaign_id');

        // 返信メール情報を一括取得
        $replyEmailIds = DeliverySendHistory::query()
            ->whereIn('campaign_id', $allCampaignIds)
            ->whereNotNull('reply_email_id')
            ->pluck('reply_email_id')
            ->unique()
            ->values()
            ->all();

        $replyEmails = [];
        if ($replyEmailIds) {
            $replyEmails = DB::table('emails')
                ->whereIn('id', $replyEmailIds)
                ->select('id', 'subject', 'received_at', 'is_read')
                ->get()
                ->keyBy('id');
        }

        // ── レスポンスデータ構築 ──
        $data = $threadItems->map(function ($thread) use ($campaignsByThread, $sendHistories, $replyEmails) {
            $threadKey = $thread->thread_key;
            $campaigns = $campaignsByThread->get($threadKey, collect());
            $campaignIds = $campaigns->pluck('id')->all();

            // スレッド内の全送信履歴
            $histories = collect();
            foreach ($campaignIds as $cid) {
                if ($sendHistories->has($cid)) {
                    $histories = $histories->merge($sendHistories->get($cid));
                }
            }

            // 送信成功件数
            $sentCount = $campaigns->sum('success_count');

            // 返信を持つ履歴
            $repliedHistories = $histories->whereNotNull('reply_email_id');
            $replyCount = $repliedHistories->count();

            // thread_count = 送信件数 + 返信件数
            $threadCount = $sentCount + $replyCount;

            // has_unread_reply: 返信メールのうち未読があるか
            $hasUnreadReply = false;
            foreach ($repliedHistories as $h) {
                $replyEmail = $replyEmails[$h->reply_email_id] ?? null;
                if ($replyEmail && !$replyEmail->is_read) {
                    $hasUnreadReply = true;
                    break;
                }
            }

            // last_sent / last_received
            $lastSent = null;
            $lastReceived = null;

            $latestCampaign = $campaigns->sortByDesc('sent_at')->first();
            if ($latestCampaign) {
                $lastSent = [
                    'type'     => 'sent',
                    'subject'  => $latestCampaign->subject,
                    'datetime' => $latestCampaign->sent_at instanceof \Carbon\Carbon
                        ? $latestCampaign->sent_at->toIso8601String()
                        : $latestCampaign->sent_at,
                ];
            }

            $latestReply = $repliedHistories->sortByDesc('replied_at')->first();
            $latestReplyEmail = $latestReply ? ($replyEmails[$latestReply->reply_email_id] ?? null) : null;
            if ($latestReplyEmail && $latestReplyEmail->received_at) {
                $lastReceived = [
                    'type'     => 'received',
                    'subject'  => $latestReplyEmail->subject,
                    'datetime' => $latestReplyEmail->received_at instanceof \Carbon\Carbon
                        ? $latestReplyEmail->received_at->toIso8601String()
                        : (string) $latestReplyEmail->received_at,
                ];
            }

            // 後方互換: last_activity（最新のどちらか）
            $lastActivity = $lastReceived
                && (!$lastSent || ($lastReceived['datetime'] ?? '') > ($lastSent['datetime'] ?? ''))
                ? $lastReceived : $lastSent;

            // partner情報: 最新の送信履歴の宛先
            $latestHistory = $histories->sortByDesc('id')->first();
            $partnerEmail = $latestHistory->email ?? null;
            $partnerName  = $latestHistory->name ?? null;

            // type別の情報
            $isProject = $thread->project_mail_id !== null;

            return [
                'id'             => (int) ($isProject ? $thread->project_mail_id : $thread->engineer_mail_source_id),
                'type'           => $isProject ? 'project' : 'engineer',
                'source_id'      => (int) $thread->source_id,
                'customer_name'  => $isProject ? $thread->customer_name : null,
                'title'          => $isProject ? $thread->project_title : $thread->engineer_name,
                'status'         => $isProject ? $thread->project_status : $thread->engineer_status,
                'partner_email'  => $partnerEmail,
                'partner_name'   => $partnerName,
                'last_activity'  => $lastActivity,
                'last_sent'      => $lastSent,
                'last_received'  => $lastReceived,
                'thread_count'   => $threadCount,
                'has_unread_reply' => $hasUnreadReply,
            ];
        })->values();

        return response()->json([
            'data'         => $data,
            'current_page' => $paginated->currentPage(),
            'last_page'    => $paginated->lastPage(),
            'total'        => $paginated->total(),
        ]);
    }

    /**
     * 件名・本文から未置換プレースホルダを抽出。
     * <%Name%> は配信時に per-recipient で置換されるため除外。
     */
    private function findUnresolvedPlaceholders(string $text): array
    {
        $found = [];
        if (preg_match_all('/<送信者[^>]*>/u', $text, $m)) {
            foreach (array_unique($m[0]) as $p) $found[] = $p;
        }
        return $found;
    }
}
