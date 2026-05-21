<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\ProposalMail;
use App\Models\DeliveryCampaign;
use App\Models\DeliverySendHistory;
use App\Models\Engineer;
use App\Models\EngineerMailSource;
use App\Models\EngineerProfile;
use App\Models\EngineerSkill;
use App\Models\ProjectMailSource;
use App\Models\Skill;
use App\Services\ClaudeService;
use App\Services\FreshMailMatchingService;
use App\Services\ProjectMailMatchingService;
use App\Services\ProjectMailScoringService;
use App\Services\ProposalStatusService;
use App\Services\RequirementMatchingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ProjectMailController extends Controller
{
    public function __construct(
        private ProjectMailScoringService    $scoringService,
        private ProjectMailMatchingService   $matchingService,
        private ClaudeService                $claudeService,
        private FreshMailMatchingService     $freshMatching,
        private ProposalStatusService        $proposalStatus,
        private RequirementMatchingService   $requirementMatching,
    ) {}

    // 一覧
    public function index(Request $request)
    {
        $perPage   = $request->integer('per_page', 30);
        $status    = $request->input('status');    // new / review / proposed / interview / won / lost / excluded
        $scoreMin  = $request->integer('score_min', 0);
        $scoreMax  = $request->integer('score_max', 100);
        $search    = $request->input('search');

        $query = ProjectMailSource::with(['email:id,subject,from_name,from_address,received_at'])
            ->whereBetween('score', [$scoreMin, $scoreMax])
            ->orderByDesc('received_at');

        if ($status) {
            $query->where('status', $status);
        } else {
            // デフォルト: excluded は除外
            $query->whereNotIn('status', ['excluded']);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'ilike', "%{$search}%")
                  ->orWhere('customer_name', 'ilike', "%{$search}%")
                  ->orWhere('work_location', 'ilike', "%{$search}%")
                  ->orWhere('sales_contact', 'ilike', "%{$search}%")
                  ->orWhereHas('email', fn($eq) => $eq
                      ->where('from_address', 'ilike', "%{$search}%")
                      ->orWhere('from_name', 'ilike', "%{$search}%")
                      ->orWhere('subject', 'ilike', "%{$search}%"));
            });
        }

        return response()->json($query->paginate($perPage));
    }

    // 詳細（元メール・添付含む）
    public function show(int $id)
    {
        $pms = ProjectMailSource::with([
            'email.attachments',
        ])->findOrFail($id);

        return response()->json($pms);
    }

    // 抽出情報の手動修正
    public function update(Request $request, int $id)
    {
        $pms = ProjectMailSource::findOrFail($id);

        $v = $request->validate([
            'customer_name'    => 'nullable|string|max:200',
            'sales_contact'    => 'nullable|string|max:100',
            'phone'            => 'nullable|string|max:50',
            'title'            => 'nullable|string|max:300',
            'required_skills'  => 'nullable|array',
            'required_skills.*'=> 'string|max:100',
            'preferred_skills' => 'nullable|array',
            'preferred_skills.*'=> 'string|max:100',
            'process'          => 'nullable|array',
            'process.*'        => 'string|max:100',
            'work_location'    => 'nullable|string|max:200',
            'remote_ok'        => 'nullable|boolean',
            'unit_price_min'   => 'nullable|numeric|min:0',
            'unit_price_max'   => 'nullable|numeric|min:0',
            'age_limit'        => 'nullable|string|max:50',
            'nationality_ok'   => 'nullable|boolean',
            'contract_type'    => 'nullable|string|max:50',
            'start_date'       => 'nullable|string|max:50',
            'supply_chain'     => 'nullable|integer|min:1|max:9',
        ]);

        $pms->update($v);

        return response()->json($pms->fresh());
    }

    // ステータス変更
    public function updateStatus(Request $request, int $id)
    {
        $pms = ProjectMailSource::findOrFail($id);

        $v = $request->validate([
            'status'      => 'required|in:new,review,proposed,interview,won,lost,excluded',
            'lost_reason' => 'nullable|string',
        ]);

        $pms->update($v);

        return response()->json($pms->fresh());
    }

    // 再スコアリング（手動トリガー）
    public function rescore(int $id)
    {
        $pms = ProjectMailSource::with('email')->findOrFail($id);

        try {
            $updated = $this->scoringService->score($pms->email);
            return response()->json($updated->fresh());
        } catch (\Exception $e) {
            Log::error("Rescore failed email_id={$pms->email_id}: " . $e->getMessage());
            return response()->json(['message' => '再スコアリングに失敗しました'], 500);
        }
    }

    // 既存レコードを全件再スコアリング＋再抽出（バッチ処理対応）
    public function rescoreAll(Request $request): JsonResponse
    {
        set_time_limit(120);
        ini_set('memory_limit', '512M');
        $batchSize = 300;
        $offset    = $request->integer('offset', 0);
        $count     = $this->scoringService->rescoreAll($batchSize, $offset);
        $total     = ProjectMailSource::whereNotNull('email_id')->count();
        $remaining = max(0, $total - ($offset + $count));

        return response()->json([
            'message'   => "{$count}件を再スコアリングしました",
            'count'     => $count,
            'remaining' => $remaining,
            'offset'    => $offset + $count,
        ]);
    }

    // 既存レコードの抽出情報を一括再計算（バッチ処理対応）
    public function reextractAll(Request $request): JsonResponse
    {
        set_time_limit(120);
        ini_set('memory_limit', '512M');
        $batchSize = 300;
        $offset    = $request->integer('offset', 0);
        $count     = $this->scoringService->reextractAll($batchSize, $offset);
        $total     = ProjectMailSource::whereNotNull('email_id')->count();
        $remaining = max(0, $total - ($offset + $count));

        return response()->json([
            'message'   => "{$count}件の抽出情報を更新しました",
            'count'     => $count,
            'remaining' => $remaining,
            'offset'    => $offset + $count,
        ]);
    }

    /**
     * 提案メール草稿を生成
     * POST /v1/project-mails/{id}/generate-proposal
     */
    public function generateProposal(Request $request, int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $mail     = ProjectMailSource::with('email')->where('tenant_id', $tenantId)->findOrFail($id);

        $v = $request->validate(['engineer_id' => 'required|integer']);

        $engineer = \App\Models\Engineer::with(['profile', 'engineerSkills.skill'])
            ->where('tenant_id', $tenantId)
            ->findOrFail($v['engineer_id']);

        $mailData = [
            'title'           => $mail->title,
            'email_subject'   => $mail->email?->subject,
            'from_address'    => $mail->email?->from_address,
            'from_name'       => $mail->email?->from_name,
            'sales_contact'   => $mail->sales_contact,
            'required_skills' => $mail->required_skills ?? [],
            'work_location'   => $mail->work_location,
            'unit_price_min'  => $mail->unit_price_min,
            'unit_price_max'  => $mail->unit_price_max,
        ];

        $engineerData = [
            'name'                    => $engineer->name,
            'age'                     => $engineer->age,
            'affiliation'             => $engineer->affiliation,
            'availability_status'     => $engineer->profile?->availability_status,
            'available_from'          => $engineer->profile?->available_from,
            'desired_unit_price_min'  => $engineer->profile?->desired_unit_price_min,
            'desired_unit_price_max'  => $engineer->profile?->desired_unit_price_max,
            'skills' => $engineer->engineerSkills->map(fn($es) => [
                'name'             => $es->skill?->name,
                'experience_years' => $es->experience_years,
            ])->values()->toArray(),
        ];

        try {
            $draft = $this->claudeService->generateProposal($mailData, $engineerData);
            return response()->json($draft);
        } catch (\App\Exceptions\ClaudeOverloadedException $e) {
            Log::warning("generateProposal overloaded mail_id={$id}: " . $e->getMessage());
            return response()->json([
                'message' => 'Claude API が混雑しています。しばらく待ってから再試行してください。',
                'code'    => 'claude_overloaded',
            ], 503);
        } catch (\Exception $e) {
            Log::error("generateProposal failed mail_id={$id}: " . $e->getMessage());
            return response()->json(['message' => 'メール生成に失敗しました'], 500);
        }
    }

    /**
     * 提案メール送信
     * POST /v1/project-mails/{id}/send-proposal
     */
    public function sendProposal(Request $request, int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        ProjectMailSource::where('tenant_id', $tenantId)->findOrFail($id);

        $v = $request->validate([
            'to'            => 'required|email',
            'to_name'       => 'nullable|string|max:255',
            'subject'       => 'required|string|max:500',
            'body'          => 'required|string',
            'attachments'   => 'nullable|array',
            'attachments.*' => 'file|max:10240',
        ]);

        $userId      = auth()->id();
        $senderName  = auth()->user()->name  ?? '';
        $senderEmail = $this->replyToAddress();

        $attachmentPaths = [];
        if ($request->hasFile('attachments')) {
            $dir = storage_path('app/temp/proposals/' . uniqid());
            @mkdir($dir, 0755, true);
            foreach ($request->file('attachments') as $file) {
                $dest = $dir . '/' . $file->getClientOriginalName();
                $file->move($dir, $file->getClientOriginalName());
                $attachmentPaths[] = $dest;
            }
        }

        $campaign = DeliveryCampaign::create([
            'tenant_id'       => $tenantId,
            'send_type'       => 'proposal',
            'project_mail_id' => $id,
            'user_id'         => $userId,
            'subject'         => $v['subject'],
            'body'            => $v['body'],
            'total_count'     => 1,
            'success_count'   => 0,
            'failed_count'    => 0,
            'sent_at'         => now(),
        ]);

        $messageId = '<' . Str::uuid() . '@aizen-sol.co.jp>';
        try {
            Mail::to($v['to'])->send(new ProposalMail($v['subject'], $v['body'], $senderName, $senderEmail, $attachmentPaths, $messageId));
            DeliverySendHistory::create([
                'tenant_id'      => $tenantId,
                'campaign_id'    => $campaign->id,
                'email'          => $v['to'],
                'name'           => $v['to_name'] ?? null,
                'status'         => 'sent',
                'ses_message_id' => $messageId,
            ]);
            $campaign->update(['success_count' => 1]);
            Log::info("提案メール送信 project_mail_id={$id} to={$v['to']}");
            return response()->json(['message' => '送信しました']);
        } catch (\Exception $e) {
            DeliverySendHistory::create([
                'tenant_id'      => $tenantId,
                'campaign_id'    => $campaign->id,
                'email'          => $v['to'],
                'name'           => $v['to_name'] ?? null,
                'status'         => 'failed',
                'ses_message_id' => $messageId,
                'error_message'  => $e->getMessage(),
            ]);
            $campaign->update(['failed_count' => 1]);
            Log::error("提案メール送信失敗 project_mail_id={$id}: " . $e->getMessage());
            return response()->json(['message' => 'メール送信に失敗しました'], 500);
        } finally {
            foreach ($attachmentPaths as $path) { if (is_file($path)) @unlink($path); }
            if ($attachmentPaths) {
                $dir = dirname($attachmentPaths[0]);
                if (is_dir($dir) && count(array_diff(scandir($dir), ['.', '..'])) === 0) @rmdir($dir);
            }
        }
    }

    /**
     * 一斉配信
     * POST /v1/project-mails/{id}/send-bulk
     */
    public function sendBulk(Request $request, int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        ProjectMailSource::where('tenant_id', $tenantId)->findOrFail($id);

        $v = $request->validate([
            'recipients'         => 'required|array|min:1|max:100',
            'recipients.*.to'   => 'required|email',
            'recipients.*.name' => 'nullable|string|max:200',
            'subject'            => 'required|string|max:500',
            'body'               => 'required|string',
        ]);

        $sent        = 0;
        $failed      = [];
        $userId      = auth()->id();
        $senderName  = auth()->user()->name  ?? '';
        $senderEmail = $this->replyToAddress();

        $campaign = DeliveryCampaign::create([
            'tenant_id'       => $tenantId,
            'send_type'       => 'bulk',
            'project_mail_id' => $id,
            'user_id'         => $userId,
            'subject'         => $v['subject'],
            'body'            => $v['body'],
            'total_count'     => count($v['recipients']),
            'success_count'   => 0,
            'failed_count'    => 0,
            'sent_at'         => now(),
        ]);

        foreach ($v['recipients'] as $recipient) {
            $messageId = '<' . Str::uuid() . '@aizen-sol.co.jp>';
            try {
                Mail::to($recipient['to'])->send(new ProposalMail($v['subject'], $v['body'], $senderName, $senderEmail, [], $messageId));
                DeliverySendHistory::create([
                    'tenant_id'      => $tenantId,
                    'campaign_id'    => $campaign->id,
                    'email'          => $recipient['to'],
                    'name'           => $recipient['name'] ?? null,
                    'status'         => 'sent',
                    'ses_message_id' => $messageId,
                ]);
                $sent++;
            } catch (\Exception $e) {
                DeliverySendHistory::create([
                    'tenant_id'      => $tenantId,
                    'campaign_id'    => $campaign->id,
                    'email'          => $recipient['to'],
                    'name'           => $recipient['name'] ?? null,
                    'status'         => 'failed',
                    'ses_message_id' => $messageId,
                    'error_message'  => $e->getMessage(),
                ]);
                Log::error("一斉配信失敗 project_mail_id={$id} to={$recipient['to']}: " . $e->getMessage());
                $failed[] = $recipient['to'];
            }
        }

        $campaign->update([
            'success_count' => $sent,
            'failed_count'  => count($failed),
        ]);

        Log::info("一斉配信完了 project_mail_id={$id} sent={$sent} failed=" . count($failed));

        return response()->json([
            'message' => "{$sent}件送信しました",
            'sent'    => $sent,
            'failed'  => $failed,
        ]);
    }

    /**
     * スレッド会話履歴
     * GET /v1/project-mails/{id}/thread
     */
    public function thread(int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        ProjectMailSource::where('tenant_id', $tenantId)->findOrFail($id);

        $campaigns = DeliveryCampaign::with(['sendHistories' => function ($q) {
                // deliveryタイプは返信ありのみロード（大量送信履歴の対策）
                $q->with(['replyEmail.attachments']);
            }])
            ->where('tenant_id', $tenantId)
            ->where('project_mail_id', $id)
            ->whereIn('send_type', ['proposal', 'matching_proposal', 'bulk'])
            ->orderBy('sent_at')
            ->get();

        $thread = [];

        $mapAttachments = fn($email) => $email?->attachments
            ? $email->attachments->map(fn($a) => [
                'id'        => $a->id,
                'filename'  => $a->filename,
                'mime_type' => $a->mime_type,
                'size'      => $a->size,
            ])->values()->all()
            : [];

        foreach ($campaigns as $campaign) {
            $isDelivery = $campaign->send_type === 'delivery';

            if ($isDelivery) {
                // 一斉配信: 送信サマリー1件 + 返信のみ
                $thread[] = [
                    'type'          => 'sent',
                    'campaign_id'   => $campaign->id,
                    'to'            => "一斉配信（{$campaign->success_count}件）",
                    'to_name'       => null,
                    'subject'       => $campaign->subject,
                    'body'          => str_replace('<%Name%>', '（各配信先名）', $campaign->body),
                    'sent_at'       => $campaign->sent_at?->toIso8601String(),
                    'status'        => 'sent',
                    'total_count'   => $campaign->total_count,
                    'success_count' => $campaign->success_count,
                    'failed_count'  => $campaign->failed_count,
                ];
                foreach ($campaign->sendHistories->filter(fn($h) => $h->replyEmail) as $history) {
                    $reply = $history->replyEmail;
                    $thread[] = [
                        'type'        => 'received',
                        'email_id'    => $reply->id,
                        'from'        => $reply->from_address,
                        'from_name'   => $reply->from_name,
                        'subject'     => $reply->subject,
                        'body_text'   => $reply->body_text,
                        'received_at' => $reply->received_at?->toIso8601String(),
                        'attachments' => $mapAttachments($reply),
                    ];
                }
                continue;
            }

            foreach ($campaign->sendHistories as $history) {
                // 個別提案: 従来通り
                $thread[] = [
                    'type'          => 'sent',
                    'campaign_id'   => $campaign->id,
                    'history_id'    => $history->id,
                    'to'            => $history->email,
                    'to_name'       => $history->name,
                    'subject'       => $campaign->subject,
                    'body'          => $campaign->body,
                    'sent_at'       => $history->created_at?->toIso8601String(),
                    'resent_at'     => $history->resent_at?->toIso8601String(),
                    'status'        => $history->status,
                    'total_count'   => $campaign->total_count,
                    'success_count' => $campaign->success_count,
                    'failed_count'  => $campaign->failed_count,
                ];

                if ($history->replyEmail) {
                    $reply = $history->replyEmail;
                    $thread[] = [
                        'type'        => 'received',
                        'email_id'    => $reply->id,
                        'from'        => $reply->from_address,
                        'from_name'   => $reply->from_name,
                        'subject'     => $reply->subject,
                        'body_text'   => $reply->body_text,
                        'received_at' => $reply->received_at?->toIso8601String(),
                        'attachments' => $mapAttachments($reply),
                    ];
                }
            }
        }

        // 時系列ソート
        usort($thread, function ($a, $b) {
            $aTime = $a['sent_at'] ?? $a['received_at'] ?? '';
            $bTime = $b['sent_at'] ?? $b['received_at'] ?? '';
            return strcmp($aTime, $bTime);
        });

        return response()->json(['thread' => $thread]);
    }

    /**
     * 案件メールに対するマッチング技術者一覧
     * GET /v1/project-mails/{id}/matched-engineers
     */
    public function matchedEngineers(int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $mail = ProjectMailSource::where('tenant_id', $tenantId)->findOrFail($id);

        $results = $this->matchingService->matchEngineers($mail, 20);

        return response()->json([
            'data' => $results->map(fn($r) => [
                'engineer_id'         => $r['engineer']->id,
                'engineer_name'       => $r['engineer']->name,
                'email'               => $r['engineer']->email,
                'affiliation'         => $r['engineer']->affiliation,
                'affiliation_contact' => $r['engineer']->affiliation_contact,
                'affiliation_email'   => $r['engineer']->affiliation_email,
                'affiliation_type'    => $r['engineer']->affiliation_type,
                'engineer_mail_source_id' => $r['engineer']->engineer_mail_source_id,
                'age'                 => $r['engineer']->age,
                'score'            => $r['score'],
                'breakdown'        => $r['breakdown'],
                'reasons'          => $r['reasons'],
                'availability_status'    => $r['engineer']->profile?->availability_status,
                'available_from'         => $r['engineer']->profile?->available_from,
                'work_style'             => $r['engineer']->profile?->work_style,
                'desired_unit_price_min' => $r['engineer']->profile?->desired_unit_price_min,
                'desired_unit_price_max' => $r['engineer']->profile?->desired_unit_price_max,
                'skills' => $r['engineer']->engineerSkills->map(fn($es) => [
                    'name'             => $es->skill?->name,
                    'experience_years' => $es->experience_years,
                ])->values(),
            ]),
        ]);
    }

    /**
     * 鮮度マッチング: 過去N日の EngineerMailSource を案件メールに対してスコアリング
     * GET /v1/project-mails/{id}/fresh-engineer-mails?days=7
     * docs/470_fresh_mail_matching.md §8.4
     */
    public function freshEngineerMails(Request $request, int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $mail = ProjectMailSource::where('tenant_id', $tenantId)->findOrFail($id);

        $v = $request->validate([
            'days'      => 'nullable|integer|min:1|max:30',
            'limit'     => 'nullable|integer|min:1|max:200',
            'min_score' => 'nullable|integer|in:50,60,70',
        ]);
        $days     = $v['days']      ?? 7;
        $limit    = $v['limit']     ?? 50;
        $minScore = $v['min_score'] ?? \App\Services\FreshMailMatchingService::RESULT_SCORE_FLOOR;

        $results = $this->freshMatching->freshEngineerMails($mail, $days, $limit, $minScore);
        $statusMap = $this->proposalStatus->buildEmsStatusMap(
            $results->pluck('ems'),
            $mail,
        );

        return response()->json([
            'days'      => $days,
            'min_score' => $minScore,
            'count'     => $results->count(),
            'data'      => $results->map(function ($r) use ($statusMap) {
                $ems    = $r['ems'];
                $status = $statusMap[$ems->id] ?? ['badge' => 'new', 'engineer_id' => null];
                return [
                    'engineer_mail_source_id' => $ems->id,
                    'name'                    => $ems->name,
                    'age'                     => $ems->age,
                    'affiliation'             => $ems->affiliation,
                    'affiliation_type'        => $ems->affiliation_type,
                    'nearest_station'         => $ems->nearest_station,
                    'skills'                  => $ems->skills,
                    'unit_price_min'          => $ems->unit_price_min,
                    'unit_price_max'          => $ems->unit_price_max,
                    'available_from'          => $ems->available_from,
                    'received_at'             => $ems->received_at?->toIso8601String(),
                    'email_from_address'      => $ems->email?->from_address,
                    'email_subject'           => $ems->email?->subject,
                    'email_body'              => EngineerMailController::pickMailBody($ems->email),
                    'score'                   => $r['score'],
                    'breakdown'               => $r['breakdown'],
                    'reasons'                 => $r['reasons'],
                    'badge'                   => $status['badge'],
                    'registered_engineer_id'  => $status['engineer_id'],
                ];
            }),
        ]);
    }

    /**
     * 鮮度マッチング: EMS を起点に Engineer 化（重複検出）→ 提案メール送信
     * POST /v1/project-mails/{id}/send-proposal-from-ems
     * docs/470_fresh_mail_matching.md §8.5
     */
    public function sendProposalFromEms(Request $request, int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $mail = ProjectMailSource::where('tenant_id', $tenantId)->findOrFail($id);

        $v = $request->validate([
            'engineer_mail_source_id' => 'required|integer',
            'to'      => 'required|email',
            'to_name' => 'nullable|string|max:255',
            'subject' => 'required|string|max:500',
            'body'    => 'required|string',
        ]);

        $ems = EngineerMailSource::with('email')
            ->where('tenant_id', $tenantId)
            ->findOrFail($v['engineer_mail_source_id']);

        // ── 1. Engineer 化（重複検出 → 既存再利用 or 新規作成）────
        $engineer = DB::transaction(function () use ($ems, $tenantId) {
            $existing = $this->findExistingEngineer($ems, $tenantId);
            if ($existing) return $existing;
            return $this->createEngineerFromEms($ems);
        });

        // ── 2. 提案メール送信（トランザクション外）────
        $userId      = auth()->id();
        $senderName  = auth()->user()->name  ?? '';
        $senderEmail = $this->replyToAddress();

        $campaign = DeliveryCampaign::create([
            'tenant_id'               => $tenantId,
            'send_type'               => 'matching_proposal',
            'project_mail_id'         => $id,
            'engineer_mail_source_id' => $ems->id,
            'user_id'                 => $userId,
            'subject'                 => $v['subject'],
            'body'                    => $v['body'],
            'total_count'             => 1,
            'success_count'           => 0,
            'failed_count'            => 0,
            'sent_at'                 => now(),
        ]);

        $messageId = '<' . Str::uuid() . '@aizen-sol.co.jp>';
        try {
            Mail::to($v['to'])->send(new ProposalMail($v['subject'], $v['body'], $senderName, $senderEmail, [], $messageId));
            DeliverySendHistory::create([
                'tenant_id'      => $tenantId,
                'campaign_id'    => $campaign->id,
                'engineer_id'    => $engineer->id,
                'email'          => $v['to'],
                'name'           => $v['to_name'] ?? null,
                'status'         => 'sent',
                'ses_message_id' => $messageId,
            ]);
            $campaign->update(['success_count' => 1]);
            Log::info("鮮度マッチング提案送信 project_mail_id={$id} ems_id={$ems->id} engineer_id={$engineer->id} to={$v['to']}");
            return response()->json([
                'message'     => '送信しました',
                'engineer_id' => $engineer->id,
                'created_new' => $engineer->wasRecentlyCreated,
            ]);
        } catch (\Exception $e) {
            DeliverySendHistory::create([
                'tenant_id'      => $tenantId,
                'campaign_id'    => $campaign->id,
                'engineer_id'    => $engineer->id,
                'email'          => $v['to'],
                'name'           => $v['to_name'] ?? null,
                'status'         => 'failed',
                'ses_message_id' => $messageId,
                'error_message'  => $e->getMessage(),
            ]);
            $campaign->update(['failed_count' => 1]);
            Log::error("鮮度マッチング提案失敗 project_mail_id={$id} ems_id={$ems->id}: " . $e->getMessage());
            return response()->json(['message' => 'メール送信に失敗しました'], 500);
        }
    }

    /**
     * EMS と既存 Engineer の照合（dedup キー: email + name + affiliation）
     */
    private function findExistingEngineer(EngineerMailSource $ems, int $tenantId): ?Engineer
    {
        // (1) engineer_mail_source_id でリンク済を優先
        $linked = Engineer::where('tenant_id', $tenantId)
            ->where('engineer_mail_source_id', $ems->id)
            ->first();
        if ($linked) return $linked;

        // (2) email + name + affiliation の3項目完全一致
        $email = $ems->email?->from_address;
        $name  = $ems->name;
        if (!$email || !$name) return null;

        $key = $this->proposalStatus->dedupKey($email, $name, $ems->affiliation);
        $candidates = Engineer::where('tenant_id', $tenantId)
            ->where('email', $email)
            ->get();
        foreach ($candidates as $eng) {
            if ($this->proposalStatus->dedupKey($eng->email, $eng->name, $eng->affiliation) === $key) {
                return $eng;
            }
        }
        return null;
    }

    /**
     * EMS から Engineer マスタへ転記（既存 EngineerMailController::registerEngineer 相当）
     */
    private function createEngineerFromEms(EngineerMailSource $ems): Engineer
    {
        $engineer = Engineer::create([
            'name'                    => $ems->name ?? '（名前未取得）',
            'email'                   => $ems->email?->from_address,
            'affiliation'             => $ems->affiliation,
            'affiliation_type'        => $ems->affiliation_type,
            'affiliation_email'       => $ems->email?->from_address,
            'nearest_station'         => $ems->nearest_station,
            'age'                     => $ems->age,
            'engineer_mail_source_id' => $ems->id,
        ]);

        EngineerProfile::create([
            'tenant_id'              => $engineer->tenant_id,
            'engineer_id'            => $engineer->id,
            'desired_unit_price_min' => $ems->unit_price_min,
            'desired_unit_price_max' => $ems->unit_price_max,
        ]);

        foreach ((array) ($ems->skills ?? []) as $skillName) {
            $skillName = trim((string) $skillName);
            if ($skillName === '') continue;
            $skill = Skill::firstOrCreate(['name' => $skillName], ['category' => 'other']);
            EngineerSkill::firstOrCreate([
                'tenant_id'   => $engineer->tenant_id,
                'engineer_id' => $engineer->id,
                'skill_id'    => $skill->id,
            ]);
        }

        $ems->update(['status' => 'registered']);
        return $engineer;
    }

    private function replyToAddress(): string
    {
        return config('mail.reply_to.address', config('mail.from.address')) ?? '';
    }

    // ── 案件要件 × 技術者スキル 対照表 (docs/480 §5) ─────────────────────────

    /**
     * PMS の構造化要件取得。無ければ Claude Stage 1 で自動生成・キャッシュ。
     * GET /v1/project-mails/{id}/requirements
     */
    public function requirements(int $id): JsonResponse
    {
        $this->ensureFeatureEnabled();

        $pms = ProjectMailSource::with('email')->findOrFail($id);
        $requirements = $this->requirementMatching->extractRequirements($pms);

        return response()->json([
            'project_mail_source_id'       => $pms->id,
            'requirements'                 => $requirements,
            'ai_requirements_generated_at' => $pms->ai_requirements_generated_at,
        ]);
    }

    /**
     * PMS 要件の強制再生成。
     * POST /v1/project-mails/{id}/requirements/regenerate
     */
    public function regenerateRequirements(int $id): JsonResponse
    {
        $this->ensureFeatureEnabled();

        $pms = ProjectMailSource::with('email')->findOrFail($id);
        $requirements = $this->requirementMatching->extractRequirements($pms, forceRefresh: true);

        return response()->json([
            'project_mail_source_id'       => $pms->id,
            'requirements'                 => $requirements,
            'ai_requirements_generated_at' => $pms->ai_requirements_generated_at,
        ]);
    }

    /**
     * PMS × EMS|Engineer の対照表取得。無ければ Stage 2 で生成。
     * GET /v1/project-mails/{id}/requirement-match?ems_id=N (or engineer_id=N)
     */
    public function requirementMatch(Request $request, int $id): JsonResponse
    {
        $this->ensureFeatureEnabled();

        $v = $request->validate([
            'ems_id'      => 'nullable|integer|exists:engineer_mail_sources,id',
            'engineer_id' => 'nullable|integer|exists:engineers,id',
        ]);
        if (empty($v['ems_id']) && empty($v['engineer_id'])) {
            return response()->json(['message' => 'ems_id または engineer_id のいずれかが必要です'], 422);
        }

        $pms = ProjectMailSource::with('email')->findOrFail($id);
        $candidate = !empty($v['ems_id'])
            ? EngineerMailSource::findOrFail($v['ems_id'])
            : Engineer::findOrFail($v['engineer_id']);

        $result = $this->requirementMatching->getOrGenerate($pms, $candidate);
        return response()->json($result);
    }

    /**
     * PMS × EMS|Engineer の対照表強制再生成。
     * POST /v1/project-mails/{id}/requirement-match/regenerate
     */
    public function regenerateRequirementMatch(Request $request, int $id): JsonResponse
    {
        $this->ensureFeatureEnabled();

        $v = $request->validate([
            'ems_id'      => 'nullable|integer|exists:engineer_mail_sources,id',
            'engineer_id' => 'nullable|integer|exists:engineers,id',
        ]);
        if (empty($v['ems_id']) && empty($v['engineer_id'])) {
            return response()->json(['message' => 'ems_id または engineer_id のいずれかが必要です'], 422);
        }

        $pms = ProjectMailSource::with('email')->findOrFail($id);
        $candidate = !empty($v['ems_id'])
            ? EngineerMailSource::findOrFail($v['ems_id'])
            : Engineer::findOrFail($v['engineer_id']);

        $result = $this->requirementMatching->regenerate($pms, $candidate);
        return response()->json($result);
    }

    /**
     * 複数候補をまとめて判定 (上限ガード付き)。
     * POST /v1/project-mails/{id}/requirement-match-batch
     * body: { ems_ids: [..], engineer_ids: [..] }
     *
     * - 上限は config('services.anthropic.requirement_match_max_per_request') (デフォルト 5)
     * - 既に DB キャッシュにあるものは Claude を呼ばない
     * - エラーで失敗した個別候補があってもまとめて返す (results[i].error にメッセージ)
     */
    public function requirementMatchBatch(Request $request, int $id): JsonResponse
    {
        $this->ensureFeatureEnabled();

        $v = $request->validate([
            'ems_ids'        => 'nullable|array',
            'ems_ids.*'      => 'integer|exists:engineer_mail_sources,id',
            'engineer_ids'   => 'nullable|array',
            'engineer_ids.*' => 'integer|exists:engineers,id',
        ]);

        $emsIds      = $v['ems_ids'] ?? [];
        $engineerIds = $v['engineer_ids'] ?? [];
        if (empty($emsIds) && empty($engineerIds)) {
            return response()->json(['message' => 'ems_ids または engineer_ids を指定してください'], 422);
        }

        $max = (int) config('services.anthropic.requirement_match_max_per_request', 5);
        $total = count($emsIds) + count($engineerIds);
        if ($total > $max) {
            return response()->json([
                'message' => "1 リクエストあたりの上限 ({$max}件) を超えています (要求={$total}件)",
                'max'     => $max,
            ], 422);
        }

        $pms = ProjectMailSource::with('email')->findOrFail($id);

        $results = [];
        foreach ($emsIds as $emsId) {
            try {
                $ems = EngineerMailSource::findOrFail($emsId);
                $r   = $this->requirementMatching->getOrGenerate($pms, $ems);
                $results[] = ['ems_id' => $emsId, 'result' => $r];
            } catch (\Throwable $e) {
                $results[] = ['ems_id' => $emsId, 'error' => $e->getMessage()];
            }
        }
        foreach ($engineerIds as $engId) {
            try {
                $eng = Engineer::findOrFail($engId);
                $r   = $this->requirementMatching->getOrGenerate($pms, $eng);
                $results[] = ['engineer_id' => $engId, 'result' => $r];
            } catch (\Throwable $e) {
                $results[] = ['engineer_id' => $engId, 'error' => $e->getMessage()];
            }
        }

        return response()->json([
            'project_mail_source_id' => $pms->id,
            'results'                => $results,
        ]);
    }

    /** Feature flag チェック。テナント単位で無効なら 403。 */
    private function ensureFeatureEnabled(): void
    {
        $tenant = auth()->user()->tenant;
        if (!$tenant || !$tenant->feature_requirement_matching) {
            abort(403, '要件マッチング機能はこのテナントで無効です (feature_requirement_matching=false)');
        }
    }
}
